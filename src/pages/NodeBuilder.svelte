<script>
  import { onMount } from 'svelte';
  import { currentPageId, addToast } from '$lib/stores.js';
  import { pages as pagesApi } from '$lib/api.js';
  import { createNodeEditor } from '$lib/node-store.svelte.js';
  import { NODE_TYPES } from '$lib/node-tree.js';
  import LayersPanel from '$components/builder/LayersPanel.svelte';
  import NodeCanvas from '$components/builder/NodeCanvas.svelte';
  import { Undo2, Redo2, Save, Copy, Trash2, Box, Type, Image as ImageIcon, MousePointerClick, Link as LinkIcon } from 'lucide-svelte';

  const editor = createNodeEditor();

  let pageTitle = $state('Page');
  let loading = $state(true);
  let loadError = $state('');

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
        pageTitle = list[0]?.title || 'Page';
      }
      if (!id) {
        loadError = 'No page found to edit.';
        return;
      }
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

  function setText(e) { editor.updateProps(selected.id, { text: e.target.value }); }
  function setProp(key, e) { editor.updateProps(selected.id, { [key]: e.target.value }); }
  function setTag(e) { editor.setTag(selected.id, e.target.value); }

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
      <h1 class="title">{pageTitle}</h1>
    </div>

    <div class="center" role="group" aria-label="Add element">
      {#each adders as a (a.type)}
        <button class="add" onclick={() => add(a.type)} title="Add {a.label}">
          <a.icon size={15} aria-hidden="true" />
          <span>{a.label}</span>
        </button>
      {/each}
    </div>

    <div class="right">
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

  {#if loading}
    <div class="message">Loading…</div>
  {:else if loadError}
    <div class="message error">{loadError}</div>
  {:else}
    <div class="body">
      <LayersPanel {editor} />
      <NodeCanvas {editor} />
      <aside class="inspector" aria-label="Element settings">
        {#if selected}
          <div class="ins-head">{selected.type}</div>

          <label class="field">
            <span>Tag</span>
            <select value={selected.tag} onchange={setTag}>
              {#each NODE_TYPES[selected.type].tags as t (t)}
                <option value={t}>{t}</option>
              {/each}
            </select>
          </label>

          {#if selected.type === 'text'}
            <label class="field">
              <span>Text</span>
              <textarea rows="3" value={selected.props.text || ''} oninput={setText}></textarea>
            </label>
          {:else if selected.type === 'image'}
            <label class="field">
              <span>Image URL</span>
              <input type="text" value={selected.props.src || ''} oninput={(e) => setProp('src', e)} />
            </label>
            <label class="field">
              <span>Alt text</span>
              <input type="text" value={selected.props.alt || ''} oninput={(e) => setProp('alt', e)} />
            </label>
          {:else if selected.type === 'button' || selected.type === 'link'}
            <label class="field">
              <span>Label</span>
              <input type="text" value={selected.props.text || ''} oninput={setText} />
            </label>
            <label class="field">
              <span>Link URL</span>
              <input type="text" value={selected.props.href || ''} oninput={(e) => setProp('href', e)} />
            </label>
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
        {:else}
          <p class="ins-empty">Select an element on the canvas or in the layers panel to edit it.</p>
        {/if}
      </aside>
    </div>
  {/if}
</div>

<style>
  .builder {
    display: flex;
    flex-direction: column;
    height: 100%;
    min-height: 0;
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

  .left { flex: 1; min-width: 0; }
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

  .body {
    flex: 1;
    display: flex;
    min-height: 0;
  }

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
