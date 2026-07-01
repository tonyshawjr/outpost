<script>
  import { onMount } from 'svelte';
  import { currentPageId, addToast, navigate } from '$lib/stores.js';
  import { pages as pagesApi } from '$lib/api.js';
  import { createNodeEditor } from '$lib/node-store.svelte.js';
  import { NODE_TYPES } from '$lib/node-tree.js';
  import LayersPanel from '$components/builder/LayersPanel.svelte';
  import SelectorsPanel from '$components/builder/SelectorsPanel.svelte';
  import ContentPanel from '$components/builder/ContentPanel.svelte';
  import NodeCanvas from '$components/builder/NodeCanvas.svelte';
  import StylePanel from '$components/builder/StylePanel.svelte';
  import ContextMenu from '$components/builder/ContextMenu.svelte';
  import AiPanel from '$components/builder/AiPanel.svelte';
  import StyleManager from '$components/builder/StyleManager.svelte';
  import { Undo2, Redo2, Save, Copy, Trash2, Box, Type, Image as ImageIcon, MousePointerClick, Link as LinkIcon, Component, Pencil, ArrowLeft, Sparkles, Palette } from 'lucide-svelte';

  const editor = createNodeEditor();

  let pageTitle = $state('Page');
  let loading = $state(true);
  let loadError = $state('');
  let leftPanel = $state('layers');
  let editMode = $state('design');
  let aiOpen = $state(false);
  let styleManagerOpen = $state(false);

  let selected = $derived(editor.selectedNode);
  let status = $derived(
    editor.saving ? 'Saving…'
    : editor.conflict ? 'Save conflict — reload the page'
    : editor.dirty ? 'Unsaved changes'
    : 'All changes saved'
  );

  let insertTarget = $derived(
    selected && selected.type === 'container' ? selected.id : editor.tree.root
  );

  const adders = [
    { type: 'container', label: 'Container', icon: Box },
    { type: 'text', label: 'Text', icon: Type },
    { type: 'image', label: 'Image', icon: ImageIcon },
    { type: 'button', label: 'Button', icon: MousePointerClick },
    { type: 'link', label: 'Link', icon: LinkIcon },
  ];

  onMount(async () => {
    let id = $currentPageId;
    try {
      if (!id) {
        const res = await pagesApi.list();
        const list = res.pages || res.items || (Array.isArray(res) ? res : []);
        id = list[0]?.id;
      }
      if (!id) {
        loadError = 'No page found to edit.';
        return;
      }
      try {
        const res = await pagesApi.get(id);
        pageTitle = (res.page || res)?.title || 'Page';
      } catch { pageTitle = 'Page'; }
      await editor.load(id);
    } catch (e) {
      loadError = e.message || 'Failed to load page.';
    } finally {
      loading = false;
    }
  });

  $effect(() => {
    const onKey = (e) => {
      const mod = e.metaKey || e.ctrlKey;
      if (!mod) return;
      const key = e.key.toLowerCase();
      const typing = ['input', 'textarea', 'select'].includes((e.target.tagName || '').toLowerCase());
      if (key === 's') { e.preventDefault(); save(); }
      else if (key === 'z' && !typing) { e.preventDefault(); e.shiftKey ? editor.redo() : editor.undo(); }
      else if (key === 'y' && !typing) { e.preventDefault(); editor.redo(); }
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  });

  function add(type) {
    editor.insert(type, insertTarget);
  }

  function goBack() {
    if (editor.dirty && !confirm('You have unsaved changes. Leave the builder anyway?')) return;
    navigate('pages');
  }

  let isComponentRef = $derived(selected?.type === 'component-ref');

  function setText(e) { editor.updateProps(selected.id, { text: e.target.value }); }
  function setProp(key, e) { editor.updateProps(selected.id, { [key]: e.target.value }); }
  function setTag(e) { editor.setTag(selected.id, e.target.value); }

  let boundField = $derived(selected?.props?.field || null);
  let canBind = $derived(selected && ['text', 'image', 'button', 'link'].includes(selected.type));

  let primaryValue = $derived.by(() => {
    if (!selected) return '';
    const fallback = selected.type === 'image' ? (selected.props.src || '') : (selected.props.text || '');
    if (boundField) {
      const v = editor.fieldValue(boundField);
      return v != null && v !== '' ? v : fallback;
    }
    return fallback;
  });

  function setPrimary(e) {
    const v = e.target.value;
    if (boundField) editor.setFieldValue(boundField, v);
    else if (selected.type === 'image') editor.updateProps(selected.id, { src: v });
    else editor.updateProps(selected.id, { text: v });
  }

  function toggleDynamic(e) {
    if (e.target.checked) {
      const base = selected.type === 'image' ? 'image' : (selected.props.text || selected.type);
      editor.bindField(selected.id, base || 'field');
    } else {
      editor.unbindField(selected.id);
    }
  }
  function renameField(e) { editor.bindField(selected.id, e.target.value); }

  function componentize() {
    const raw = prompt('Component name', selected.type === 'container' ? 'Section' : 'Component');
    if (raw === null) return;
    editor.componentize(selected.id, raw.trim() || 'Component');
  }

  let ctx = $state(null);

  function openContext(nodeId, x, y) {
    ctx = { nodeId, x, y };
  }

  function bem(id) {
    const raw = prompt('Block name (BEM) — generates .block and .block__element classes for the whole subtree', 'block');
    if (raw === null) return;
    editor.applyBem(id, raw.trim() || 'block');
  }

  let ctxItems = $derived.by(() => {
    if (!ctx) return [];
    const id = ctx.nodeId;
    const node = editor.tree.nodes[id];
    const isRoot = id === editor.tree.root;
    return [
      { label: 'Create BEM classes…', action: () => bem(id) },
      { label: 'Componentize', action: () => { editor.select(id); componentize(); }, disabled: isRoot || node?.type === 'component-ref' },
      { divider: true },
      { label: 'Duplicate', action: () => editor.duplicate(id), disabled: isRoot },
      { label: 'Delete', action: () => editor.remove(id), danger: true, disabled: isRoot },
    ];
  });

  async function save() {
    if (!editor.dirty || editor.saving) return;
    try {
      await editor.save();
      addToast('Saved', 'success');
    } catch (e) {
      addToast(editor.conflict ? 'Someone else saved first — reload' : (e.message || 'Save failed'), 'error');
    }
  }
</script>

<div class="builder">
  <header class="toolbar">
    <div class="left">
      <button class="back-cms" onclick={goBack} title="Back to CMS" aria-label="Back to CMS">
        <ArrowLeft size={18} aria-hidden="true" />
      </button>
      <h1 class="title">{pageTitle}</h1>
      <div class="mode" role="group" aria-label="Edit mode">
        <button class:on={editMode === 'design'} aria-pressed={editMode === 'design'} onclick={() => (editMode = 'design')}>Design</button>
        <button class:on={editMode === 'content'} aria-pressed={editMode === 'content'} onclick={() => (editMode = 'content')}>Content</button>
      </div>
    </div>

    {#if editMode === 'design'}
      <div class="center" role="group" aria-label="Add element">
        {#each adders as a (a.type)}
          <button class="add" onclick={() => add(a.type)} title="Add {a.label}">
            <a.icon size={15} aria-hidden="true" />
            <span>{a.label}</span>
          </button>
        {/each}
      </div>
    {:else}
      <div class="center content-hint">Content mode — editing text &amp; media only</div>
    {/if}

    <div class="right">
      <button class="ai-toggle" onclick={() => (styleManagerOpen = true)} title="Style Manager">
        <Palette size={15} aria-hidden="true" />
        <span>Styles</span>
      </button>
      {#if editMode === 'design'}
        <button class="ai-toggle" class:on={aiOpen} aria-pressed={aiOpen} onclick={() => (aiOpen = !aiOpen)} title="Build with AI">
          <Sparkles size={15} aria-hidden="true" />
          <span>AI</span>
        </button>
      {/if}
      <button class="icon" onclick={() => editor.undo()} disabled={!editor.canUndo} aria-label="Undo" title="Undo (⌘Z)">
        <Undo2 size={17} aria-hidden="true" />
      </button>
      <button class="icon" onclick={() => editor.redo()} disabled={!editor.canRedo} aria-label="Redo" title="Redo (⇧⌘Z)">
        <Redo2 size={17} aria-hidden="true" />
      </button>
      <span class="status" role="status" aria-live="polite">{status}</span>
      <button class="save" onclick={save} disabled={!editor.dirty || editor.saving}>
        <Save size={15} aria-hidden="true" />
        <span>Save</span>
      </button>
    </div>
  </header>

  {#if editor.editingComponentId}
    <div class="cmp-banner" role="status">
      <Component size={15} aria-hidden="true" />
      <span>Editing component <strong>{editor.editingComponentName}</strong> — changes apply to every instance.</span>
      <button class="cmp-done" onclick={() => editor.exitComponent()}>
        <ArrowLeft size={14} aria-hidden="true" />
        <span>Done</span>
      </button>
    </div>
  {/if}

  {#if loading}
    <div class="message">Loading…</div>
  {:else if loadError}
    <div class="message error">{loadError}</div>
  {:else}
    <div class="body">
      <div class="left-col">
        {#if editMode === 'content'}
          <div class="left-single">Content</div>
          <ContentPanel {editor} />
        {:else}
          <div class="left-tabs" role="tablist" aria-label="Left panel">
            <button role="tab" aria-selected={leftPanel === 'layers'} class:on={leftPanel === 'layers'} onclick={() => (leftPanel = 'layers')}>Layers</button>
            <button role="tab" aria-selected={leftPanel === 'selectors'} class:on={leftPanel === 'selectors'} onclick={() => (leftPanel = 'selectors')}>Selectors</button>
          </div>
          {#if leftPanel === 'selectors'}
            <SelectorsPanel {editor} />
          {:else}
            <LayersPanel {editor} oncontext={openContext} />
          {/if}
        {/if}
      </div>
      <NodeCanvas {editor} oncontext={editMode === 'design' ? openContext : undefined} />
      <aside class="inspector" aria-label="Element settings">
        {#if selected && isComponentRef}
          <div class="ins-head">Component</div>
          <div class="cmp-name">{editor.componentName(selected.props.componentId) || 'Component'}</div>
          <p class="ins-hint">This is an instance. Edit the component to change every instance at once.</p>
          <div class="ins-actions">
            <button class="ghost" onclick={() => editor.enterComponent(selected.props.componentId)}>
              <Pencil size={14} aria-hidden="true" />
              <span>Edit component</span>
            </button>
            <button class="ghost" onclick={() => editor.duplicate(selected.id)}>
              <Copy size={14} aria-hidden="true" />
              <span>Duplicate</span>
            </button>
          </div>
          <div class="ins-actions">
            <button class="ghost danger" onclick={() => editor.remove(selected.id)} disabled={selected.id === editor.tree.root}>
              <Trash2 size={14} aria-hidden="true" />
              <span>Delete instance</span>
            </button>
          </div>
        {:else if selected}
          <div class="ins-head">{editMode === 'content' ? 'Content' : selected.type}</div>

          {#if editMode === 'design'}
            <label class="field">
              <span>Tag</span>
              <select value={selected.tag} onchange={setTag}>
                {#each NODE_TYPES[selected.type].tags as t (t)}
                  <option value={t}>{t}</option>
                {/each}
              </select>
            </label>
          {/if}

          {#if canBind && editMode === 'design'}
            <div class="dyn">
              <label class="dyn-toggle">
                <input type="checkbox" checked={!!boundField} onchange={toggleDynamic} />
                <span>Dynamic content</span>
              </label>
              {#if boundField}
                <input class="dyn-name" type="text" value={boundField} oninput={renameField} aria-label="Field name" spellcheck="false" />
                <p class="dyn-hint">Editable as a field — updates the live page without rebuilding.</p>
              {/if}
            </div>
          {/if}

          {#if selected.type === 'text'}
            <label class="field">
              <span>{boundField ? 'Content' : 'Text'}</span>
              <textarea rows="3" value={primaryValue} oninput={setPrimary}></textarea>
            </label>
          {:else if selected.type === 'image'}
            <label class="field">
              <span>{boundField ? 'Image URL (dynamic)' : 'Image URL'}</span>
              <input type="text" value={primaryValue} oninput={setPrimary} />
            </label>
            <label class="field">
              <span>Alt text</span>
              <input type="text" value={selected.props.alt || ''} oninput={(e) => setProp('alt', e)} />
            </label>
          {:else if selected.type === 'button' || selected.type === 'link'}
            <label class="field">
              <span>Label</span>
              <input type="text" value={primaryValue} oninput={setPrimary} />
            </label>
            <label class="field">
              <span>Link URL</span>
              <input type="text" value={selected.props.href || ''} oninput={(e) => setProp('href', e)} />
            </label>
          {/if}

          {#if editMode === 'design'}
            <StylePanel {editor} />

            {#if selected.id !== editor.tree.root}
              <div class="ins-actions">
                <button class="ghost" onclick={componentize}>
                  <Component size={14} aria-hidden="true" />
                  <span>Componentize</span>
                </button>
              </div>
            {/if}

            <div class="ins-actions">
              <button class="ghost" onclick={() => editor.duplicate(selected.id)}>
                <Copy size={14} aria-hidden="true" />
                <span>Duplicate</span>
              </button>
              <button
                class="ghost danger"
                onclick={() => editor.remove(selected.id)}
                disabled={selected.id === editor.tree.root}
              >
                <Trash2 size={14} aria-hidden="true" />
                <span>Delete</span>
              </button>
            </div>
          {/if}
        {:else}
          <p class="ins-empty">{editMode === 'content' ? 'Select content on the canvas or in the list to edit it.' : 'Select an element on the canvas or in the layers panel to edit it.'}</p>
        {/if}
      </aside>
      {#if aiOpen && editMode === 'design'}
        <AiPanel {editor} onclose={() => (aiOpen = false)} />
      {/if}
    </div>
  {/if}

  {#if ctx}
    <ContextMenu x={ctx.x} y={ctx.y} items={ctxItems} onclose={() => (ctx = null)} />
  {/if}

  {#if styleManagerOpen}
    <StyleManager {editor} onclose={() => (styleManagerOpen = false)} />
  {/if}
</div>

<style>
  .builder {
    display: flex;
    flex-direction: column;
    height: 100%;
    min-height: 0;
    position: relative;
  }

  .toolbar {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 12px 16px;
    border-bottom: 1px solid var(--border);
    background: var(--raised);
    flex-shrink: 0;
  }

  .left { flex: 1; min-width: 0; display: flex; align-items: center; gap: 12px; }

  .back-cms {
    display: inline-flex;
    padding: 7px;
    border: none;
    border-radius: 8px;
    background: var(--hover);
    color: var(--sec);
    cursor: pointer;
    flex-shrink: 0;
  }
  .back-cms:hover { background: var(--sidebar-bg-active); color: var(--text); }
  .back-cms:focus-visible { outline: 2px solid var(--purple); outline-offset: 1px; }

  .mode { display: inline-flex; gap: 2px; background: var(--hover); border-radius: 8px; padding: 2px; flex-shrink: 0; }
  .mode button {
    padding: 5px 12px;
    border: none;
    border-radius: 6px;
    background: transparent;
    color: var(--sec);
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
  }
  .mode button.on { background: var(--raised); color: var(--text); }
  .mode button:focus-visible { outline: 2px solid var(--purple); outline-offset: 1px; }

  .content-hint { font-size: 12px; color: var(--dim); }

  .left-single {
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--text);
    padding: 14px 14px 4px;
    flex-shrink: 0;
  }
  .right { flex: 1; display: flex; align-items: center; justify-content: flex-end; gap: 10px; }
  .center { display: flex; gap: 4px; }

  .title {
    font-size: 15px;
    font-weight: 600;
    color: var(--text);
    margin: 0;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .add {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 7px 10px;
    border: none;
    border-radius: 7px;
    background: transparent;
    color: var(--sec);
    font-size: 13px;
    font-weight: 500;
    cursor: pointer;
  }
  .add:hover { background: var(--hover); color: var(--text); }
  .add:focus-visible { outline: 2px solid var(--purple); outline-offset: 1px; }

  .icon {
    display: inline-flex;
    padding: 7px;
    border: none;
    border-radius: 7px;
    background: transparent;
    color: var(--sec);
    cursor: pointer;
  }
  .icon:hover:not(:disabled) { background: var(--hover); color: var(--text); }
  .icon:disabled { opacity: 0.35; cursor: default; }
  .icon:focus-visible { outline: 2px solid var(--purple); outline-offset: 1px; }

  .status {
    font-size: 12px;
    color: var(--dim);
    white-space: nowrap;
  }

  .save {
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
  .save:hover:not(:disabled) { background: var(--accent-hover); }
  .save:disabled { opacity: 0.4; cursor: default; }
  .save:focus-visible { outline: 2px solid var(--purple-soft); outline-offset: 2px; }

  .ai-toggle {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 7px 11px;
    border: 1px solid var(--border);
    border-radius: 8px;
    background: transparent;
    color: var(--sec);
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
  }
  .ai-toggle:hover { background: var(--hover); color: var(--text); }
  .ai-toggle.on { background: var(--purple-bg, var(--hover)); color: var(--purple-soft, var(--purple)); border-color: transparent; }
  .ai-toggle :global(svg) { color: var(--purple-soft, var(--purple)); }
  .ai-toggle:focus-visible { outline: 2px solid var(--purple); outline-offset: 1px; }

  .cmp-banner {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 9px 16px;
    background: var(--purple-bg);
    border-bottom: 1px solid var(--border);
    color: var(--text);
    font-size: 13px;
    flex-shrink: 0;
  }
  .cmp-banner strong { font-weight: 600; }
  .cmp-banner :global(svg) { color: var(--purple-soft); flex-shrink: 0; }
  .cmp-done {
    margin-left: auto;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 12px;
    border: none;
    border-radius: 7px;
    background: var(--purple);
    color: #fff;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    flex-shrink: 0;
  }
  .cmp-done:focus-visible { outline: 2px solid var(--purple-soft); outline-offset: 2px; }

  .cmp-name {
    font-size: 15px;
    font-weight: 600;
    color: var(--text);
    margin-bottom: 6px;
  }
  .ins-hint {
    font-size: 12px;
    color: var(--dim);
    line-height: 1.5;
    margin: 0 0 14px;
  }

  .body {
    flex: 1;
    display: flex;
    min-height: 0;
  }

  .left-col {
    width: 280px;
    flex-shrink: 0;
    display: flex;
    flex-direction: column;
    min-height: 0;
    background: var(--raised);
    border-right: 1px solid var(--border);
  }

  .left-tabs {
    display: flex;
    gap: 4px;
    padding: 10px 10px 6px;
    flex-shrink: 0;
  }
  .left-tabs button {
    flex: 1;
    padding: 7px 10px;
    border: none;
    border-radius: 7px;
    background: transparent;
    color: var(--sec);
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    cursor: pointer;
  }
  .left-tabs button.on { background: var(--hover); color: var(--text); }
  .left-tabs button:focus-visible { outline: 2px solid var(--purple); outline-offset: 1px; }

  .inspector {
    width: 280px;
    flex-shrink: 0;
    border-left: 1px solid var(--border);
    background: var(--raised);
    padding: 16px;
    overflow-y: auto;
  }

  .ins-head {
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--dim);
    margin-bottom: 14px;
  }

  .field {
    display: flex;
    flex-direction: column;
    gap: 5px;
    margin-bottom: 14px;
  }

  .field span {
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--dim);
  }

  .field input,
  .field select,
  .field textarea {
    width: 100%;
    padding: 8px 10px;
    border: 1px solid transparent;
    border-radius: 7px;
    background: var(--hover);
    color: var(--text);
    font-size: 13px;
    font-family: inherit;
    resize: vertical;
  }
  .field input:hover,
  .field select:hover,
  .field textarea:hover { border-color: var(--border); }
  .field input:focus-visible,
  .field select:focus-visible,
  .field textarea:focus-visible {
    outline: none;
    border-color: var(--purple);
  }

  .dyn {
    margin-bottom: 14px;
    padding: 10px 11px;
    border: 1px solid var(--border);
    border-radius: 8px;
    background: var(--hover);
  }
  .dyn-toggle {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 12.5px;
    color: var(--text);
    cursor: pointer;
  }
  .dyn-toggle input { accent-color: var(--purple); }
  .dyn-name {
    width: 100%;
    margin-top: 8px;
    padding: 7px 9px;
    border: 1px solid var(--border);
    border-radius: 6px;
    background: var(--bg);
    color: var(--text);
    font-size: 12px;
    font-family: var(--font-mono, ui-monospace, monospace);
  }
  .dyn-name:focus-visible { outline: none; border-color: var(--purple); }
  .dyn-hint { font-size: 11px; color: var(--dim); line-height: 1.45; margin: 7px 0 0; }

  .ins-actions {
    display: flex;
    gap: 8px;
    margin-top: 8px;
  }

  .ghost {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 10px;
    border: 1px solid var(--border);
    border-radius: 7px;
    background: transparent;
    color: var(--sec);
    font-size: 12px;
    font-weight: 500;
    cursor: pointer;
  }
  .ghost:hover:not(:disabled) { background: var(--hover); color: var(--text); }
  .ghost:disabled { opacity: 0.4; cursor: default; }
  .ghost:focus-visible { outline: 2px solid var(--purple); outline-offset: 1px; }
  .ghost.danger:hover:not(:disabled) { color: var(--red); }

  .ins-empty {
    font-size: 13px;
    color: var(--dim);
    line-height: 1.5;
    margin: 0;
  }

  .message {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--dim);
    font-size: 14px;
  }
  .message.error { color: var(--red); }
</style>
