<script>
  import { ArrowLeft, Plus, Trash2, Search, Save } from 'lucide-svelte';
  import { addToast } from '$lib/stores.js';

  let { editor, onclose } = $props();

  const TABS = [
    { id: 'selectors', label: 'Selectors' },
    { id: 'variables', label: 'Variables' },
    { id: 'stylesheets', label: 'Stylesheets' },
    { id: 'custom', label: 'Custom Media' },
  ];
  let tab = $state('variables');

  let search = $state('');
  let selClass = $state(null);
  let selCss = $state('');
  let cssFocused = $state(false);
  let renameTo = $state('');

  let selColId = $state(null);
  let selSheetId = $state(null);

  let classList = $derived.by(() => {
    const q = search.trim().toLowerCase();
    return editor.classNames.filter((n) => !q || n.toLowerCase().includes(q)).sort();
  });

  let collection = $derived(editor.varCollections.find((c) => c.id === selColId) || null);
  let sheet = $derived(editor.stylesheets.find((s) => s.id === selSheetId) || null);

  $effect(() => {
    if (selColId == null && editor.varCollections.length) selColId = editor.varCollections[0].id;
  });
  $effect(() => {
    if (selSheetId == null && editor.stylesheets.length) selSheetId = editor.stylesheets[0].id;
  });
  $effect(() => {
    const name = selClass;
    const serialized = name ? editor.classCssText(name) : '';
    if (!cssFocused) selCss = serialized;
  });

  function pickClass(name) {
    selClass = name;
    renameTo = name;
  }
  function onSelCss(e) {
    selCss = e.target.value;
    editor.setClassCss(selClass, selCss);
  }
  function confirmRename() {
    const to = renameTo.trim();
    if (!to || to === selClass) return;
    if (editor.renameClass(selClass, to)) {
      selClass = to;
      addToast(`Renamed to .${to}`, 'success');
    } else {
      addToast('Could not rename — name invalid or already exists', 'error');
      renameTo = selClass;
    }
  }

  async function save() {
    try {
      await editor.saveStyles();
      addToast('Styles saved', 'success');
    } catch (e) {
      addToast(e.message || 'Save failed', 'error');
    }
  }
</script>

<div class="sm" role="region" aria-label="Style Manager">
  <header class="sm-head">
    <button class="back" onclick={onclose} aria-label="Close Style Manager">
      <ArrowLeft size={16} aria-hidden="true" />
    </button>
    <h1 class="sm-title">Style Manager</h1>
    <nav class="sm-tabs" role="tablist" aria-label="Style sections">
      {#each TABS as t (t.id)}
        <button role="tab" aria-selected={tab === t.id} class:on={tab === t.id} onclick={() => (tab = t.id)}>{t.label}</button>
      {/each}
    </nav>
    <button class="save" onclick={save} disabled={editor.savingStyles}>
      <Save size={14} aria-hidden="true" />
      <span>{editor.savingStyles ? 'Saving…' : 'Save'}</span>
    </button>
  </header>

  <div class="sm-body">
    {#if tab === 'selectors'}
      <aside class="sidebar" aria-label="Selectors">
        <div class="search">
          <Search size={14} aria-hidden="true" />
          <input type="text" placeholder="Search classes" bind:value={search} aria-label="Search classes" />
        </div>
        <ul class="list">
          {#each classList as name (name)}
            <li>
              <button class="row mono" class:on={selClass === name} onclick={() => pickClass(name)}>.{name}</button>
            </li>
          {/each}
          {#if classList.length === 0}
            <li class="empty">No classes yet.</li>
          {/if}
        </ul>
      </aside>
      <section class="editor-pane">
        {#if selClass}
          <div class="pane-head">
            <input class="rename mono" bind:value={renameTo} aria-label="Rename class" spellcheck="false" />
            <button class="btn-sm" onclick={confirmRename} disabled={!renameTo.trim() || renameTo.trim() === selClass}>Confirm rename</button>
          </div>
          <textarea class="code" spellcheck="false" autocomplete="off" value={selCss} oninput={onSelCss} onfocus={() => (cssFocused = true)} onblur={() => (cssFocused = false)} aria-label="CSS for selected class"></textarea>
        {:else}
          <p class="pane-empty">Select a class to edit its CSS.</p>
        {/if}
      </section>

    {:else if tab === 'variables'}
      <aside class="sidebar" aria-label="Variable collections">
        <div class="sb-head">Collections</div>
        <ul class="list">
          {#each editor.varCollections as c (c.id)}
            <li>
              <button class="row" class:on={selColId === c.id} onclick={() => (selColId = c.id)}>{c.name}</button>
            </li>
          {/each}
          {#if editor.varCollections.length === 0}
            <li class="empty">No collections yet.</li>
          {/if}
        </ul>
        <button class="add-btn" onclick={() => (selColId = editor.addCollection('Collection'))}>
          <Plus size={14} aria-hidden="true" /> Add collection
        </button>
      </aside>
      <section class="editor-pane">
        {#if collection}
          <div class="pane-head">
            <input class="rename" value={collection.name} oninput={(e) => editor.updateCollection(collection.id, { name: e.target.value })} aria-label="Collection name" />
            <button class="btn-sm danger" onclick={() => { editor.removeCollection(collection.id); selColId = null; }} aria-label="Delete collection">
              <Trash2 size={14} aria-hidden="true" />
            </button>
          </div>
          <textarea class="code" spellcheck="false" autocomplete="off" value={collection.css} oninput={(e) => editor.updateCollection(collection.id, { css: e.target.value })} aria-label="Collection CSS"></textarea>
          <p class="hint">Define CSS variables in a <code>:root</code> block, e.g. <code>--primary: #0756a3;</code>. Applies to the canvas and every published page.</p>
        {:else}
          <p class="pane-empty">Add or select a collection to define variables.</p>
        {/if}
      </section>

    {:else if tab === 'stylesheets'}
      <aside class="sidebar" aria-label="Stylesheets">
        <div class="sb-head">Stylesheets</div>
        <ul class="list">
          {#each editor.stylesheets as s (s.id)}
            <li>
              <button class="row" class:on={selSheetId === s.id} onclick={() => (selSheetId = s.id)}>{s.name}</button>
            </li>
          {/each}
          {#if editor.stylesheets.length === 0}
            <li class="empty">No stylesheets yet.</li>
          {/if}
        </ul>
        <button class="add-btn" onclick={() => (selSheetId = editor.addStylesheet('Stylesheet'))}>
          <Plus size={14} aria-hidden="true" /> Add stylesheet
        </button>
      </aside>
      <section class="editor-pane">
        {#if sheet}
          <div class="pane-head">
            <input class="rename" value={sheet.name} oninput={(e) => editor.updateStylesheet(sheet.id, { name: e.target.value })} aria-label="Stylesheet name" />
            <button class="btn-sm danger" onclick={() => { editor.removeStylesheet(sheet.id); selSheetId = null; }} aria-label="Delete stylesheet">
              <Trash2 size={14} aria-hidden="true" />
            </button>
          </div>
          <textarea class="code" spellcheck="false" autocomplete="off" value={sheet.css} oninput={(e) => editor.updateStylesheet(sheet.id, { css: e.target.value })} aria-label="Stylesheet CSS"></textarea>
          <p class="hint">Global CSS applied site-wide. Use any selectors, at-rules, and <code>@media (--breakpoint)</code> custom-media references.</p>
        {:else}
          <p class="pane-empty">Add or select a stylesheet.</p>
        {/if}
      </section>

    {:else}
      <section class="editor-pane full">
        <div class="pane-head"><span class="pane-label">Custom media definitions</span></div>
        <textarea class="code" spellcheck="false" autocomplete="off" value={editor.customMedia} oninput={(e) => editor.setCustomMedia(e.target.value)} aria-label="Custom media definitions" placeholder={"@custom-media --sm (min-width: 480px);\n@custom-media --md (min-width: 768px);\n@custom-media --lg (min-width: 1100px);"}></textarea>
        <p class="hint">Name your breakpoints once, then use <code>@media (--md)</code> anywhere. They're expanded to real media queries on the canvas and in the published pages.</p>
      </section>
    {/if}
  </div>
</div>

<style>
  .sm {
    position: absolute;
    inset: 0;
    z-index: 30;
    display: flex;
    flex-direction: column;
    background: var(--bg);
  }
  .sm-head {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 12px 16px;
    border-bottom: 1px solid var(--border);
    background: var(--raised);
    flex-shrink: 0;
  }
  .back {
    display: inline-flex;
    padding: 6px;
    border: none;
    border-radius: 7px;
    background: transparent;
    color: var(--sec);
    cursor: pointer;
  }
  .back:hover { background: var(--hover); color: var(--text); }
  .back:focus-visible { outline: 2px solid var(--purple); outline-offset: 1px; }
  .sm-title { font-size: 15px; font-weight: 600; color: var(--text); margin: 0; white-space: nowrap; }

  .sm-tabs { display: inline-flex; gap: 2px; background: var(--hover); border-radius: 8px; padding: 2px; }
  .sm-tabs button {
    padding: 6px 12px;
    border: none;
    border-radius: 6px;
    background: transparent;
    color: var(--sec);
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
  }
  .sm-tabs button.on { background: var(--raised); color: var(--text); }
  .sm-tabs button:focus-visible { outline: 2px solid var(--purple); outline-offset: 1px; }

  .save {
    margin-left: auto;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 14px;
    border: none;
    border-radius: 8px;
    background: var(--purple);
    color: #fff;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
  }
  .save:hover:not(:disabled) { background: var(--accent-hover, var(--purple)); }
  .save:disabled { opacity: 0.5; cursor: default; }
  .save:focus-visible { outline: 2px solid var(--purple-soft, var(--purple)); outline-offset: 2px; }

  .sm-body { flex: 1; min-height: 0; display: flex; }

  .sidebar {
    width: 280px;
    flex-shrink: 0;
    border-right: 1px solid var(--border);
    background: var(--raised);
    display: flex;
    flex-direction: column;
    min-height: 0;
  }
  .sb-head {
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--dim);
    padding: 14px 14px 8px;
  }
  .search {
    display: flex;
    align-items: center;
    gap: 7px;
    margin: 12px 12px 6px;
    padding: 7px 10px;
    border: 1px solid var(--border);
    border-radius: 8px;
    background: var(--bg);
    color: var(--dim);
  }
  .search input { flex: 1; border: none; background: transparent; color: var(--text); font-size: 13px; }
  .search input:focus-visible { outline: none; }

  .list { list-style: none; margin: 0; padding: 4px 8px; overflow-y: auto; flex: 1; }
  .row {
    width: 100%;
    text-align: left;
    padding: 7px 10px;
    border: none;
    border-radius: 7px;
    background: none;
    color: var(--sec);
    font-size: 13px;
    cursor: pointer;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }
  .row.mono { font-family: var(--font-mono, ui-monospace, monospace); font-size: 12px; }
  .row:hover { background: var(--hover); color: var(--text); }
  .row.on { background: var(--sidebar-bg-active); color: var(--text); }
  .row:focus-visible { outline: 2px solid var(--purple); outline-offset: -2px; }
  .empty { padding: 12px 10px; font-size: 12px; color: var(--dim); }

  .add-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    margin: 8px 12px 12px;
    padding: 9px;
    border: 1px solid var(--border);
    border-radius: 8px;
    background: transparent;
    color: var(--sec);
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    flex-shrink: 0;
  }
  .add-btn:hover { background: var(--hover); color: var(--text); }
  .add-btn:focus-visible { outline: 2px solid var(--purple); outline-offset: 1px; }

  .editor-pane {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    padding: 16px 18px;
  }
  .editor-pane.full { padding: 16px 18px; }

  .pane-head { display: flex; align-items: center; gap: 8px; margin-bottom: 12px; }
  .pane-label { font-size: 13px; font-weight: 600; color: var(--text); }
  .rename {
    flex: 1;
    padding: 8px 11px;
    border: 1px solid var(--border);
    border-radius: 8px;
    background: var(--raised);
    color: var(--text);
    font-size: 13px;
  }
  .rename.mono { font-family: var(--font-mono, ui-monospace, monospace); }
  .rename:focus-visible { outline: none; border-color: var(--purple); }

  .btn-sm {
    padding: 8px 12px;
    border: 1px solid var(--border);
    border-radius: 8px;
    background: transparent;
    color: var(--sec);
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    flex-shrink: 0;
  }
  .btn-sm:hover:not(:disabled) { background: var(--hover); color: var(--text); }
  .btn-sm:disabled { opacity: 0.4; cursor: default; }
  .btn-sm:focus-visible { outline: 2px solid var(--purple); outline-offset: 1px; }
  .btn-sm.danger { color: var(--dim); }
  .btn-sm.danger:hover { color: var(--red); background: var(--hover); }

  .code {
    flex: 1;
    min-height: 0;
    width: 100%;
    padding: 14px 16px;
    border: 1px solid var(--border);
    border-radius: 10px;
    background: var(--raised);
    color: var(--text);
    font-family: var(--font-mono, ui-monospace, monospace);
    font-size: 13px;
    line-height: 1.6;
    resize: none;
    tab-size: 2;
  }
  .code:focus-visible { outline: none; border-color: var(--purple); }

  .hint { font-size: 12px; color: var(--dim); line-height: 1.5; margin: 10px 0 0; }
  .hint code { font-family: var(--font-mono, ui-monospace, monospace); background: var(--hover); padding: 1px 5px; border-radius: 4px; }

  .pane-empty, .pane-head + .pane-empty { font-size: 13px; color: var(--dim); margin: 0; }
</style>
