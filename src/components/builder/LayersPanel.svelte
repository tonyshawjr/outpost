<script>
  import { parentOf } from '$lib/node-tree.js';
  import { ChevronRight } from 'lucide-svelte';

  let { editor, oncontext } = $props();

  let collapsed = $state(new Set());
  let treeEl = $state(null);

  function groupFor(row) {
    if (row.type === 'component-ref') return 'component';
    return tagGroup(row.tag);
  }

  function tagGroup(tag) {
    if (['h1', 'h2', 'h3', 'h4', 'h5', 'h6'].includes(tag)) return 'heading';
    if (['p', 'span', 'strong', 'em', 'small', 'blockquote', 'label'].includes(tag)) return 'text';
    if (tag === 'img') return 'media';
    if (['a', 'button'].includes(tag)) return 'interactive';
    if (['ul', 'ol', 'li'].includes(tag)) return 'list';
    if (['section', 'main', 'header', 'footer', 'nav', 'article', 'aside', 'figure'].includes(tag)) return 'structural';
    return 'generic';
  }

  let rows = $derived.by(() => {
    const tree = editor.tree;
    const out = [];
    const walk = (id, depth) => {
      const n = tree.nodes[id];
      if (!n) return;
      const kids = n.children || [];
      out.push({ id, depth, type: n.type, tag: n.tag, classes: n.classes, hasChildren: kids.length > 0, compId: n.props?.componentId });
      if (kids.length && !collapsed.has(id)) for (const c of kids) walk(c, depth + 1);
    };
    walk(tree.root, 0);
    return out;
  });

  function toggle(id) {
    const next = new Set(collapsed);
    next.has(id) ? next.delete(id) : next.add(id);
    collapsed = next;
  }

  $effect(() => {
    const id = editor.selectedId;
    if (!id || !treeEl) return;
    const tree = editor.tree;
    const anc = [];
    let p = parentOf(tree, id);
    while (p) { anc.push(p); p = parentOf(tree, p); }
    if (anc.some((a) => collapsed.has(a))) {
      const next = new Set(collapsed);
      anc.forEach((a) => next.delete(a));
      collapsed = next;
    }
    requestAnimationFrame(() => {
      treeEl?.querySelector(`[data-layer-id="${id}"]`)?.scrollIntoView({ block: 'nearest' });
    });
  });

  function focusRow(row) {
    if (!row) return;
    editor.select(row.id);
    requestAnimationFrame(() => {
      treeEl?.querySelector(`[data-layer-id="${row.id}"]`)?.focus();
    });
  }

  function onTreeClick(e) {
    const caret = e.target.closest('[data-caret]');
    if (caret) {
      toggle(caret.getAttribute('data-caret'));
      return;
    }
    const row = e.target.closest('[data-layer-id]');
    if (row) editor.select(row.getAttribute('data-layer-id'));
  }

  function onTreeContext(e) {
    const row = e.target.closest('[data-layer-id]');
    if (!row) return;
    e.preventDefault();
    const id = row.getAttribute('data-layer-id');
    editor.select(id);
    oncontext?.(id, e.clientX, e.clientY);
  }

  function onTreeKeydown(e) {
    const list = rows;
    const idx = list.findIndex((r) => r.id === editor.selectedId);
    const cur = idx < 0 ? 0 : idx;
    const row = list[cur];
    switch (e.key) {
      case 'ArrowDown': e.preventDefault(); focusRow(list[Math.min(cur + 1, list.length - 1)]); break;
      case 'ArrowUp': e.preventDefault(); focusRow(list[Math.max(cur - 1, 0)]); break;
      case 'Home': e.preventDefault(); focusRow(list[0]); break;
      case 'End': e.preventDefault(); focusRow(list[list.length - 1]); break;
      case 'ArrowRight':
        e.preventDefault();
        if (row?.hasChildren && collapsed.has(row.id)) toggle(row.id);
        else if (row?.hasChildren) focusRow(list[cur + 1]);
        break;
      case 'ArrowLeft':
        e.preventDefault();
        if (row?.hasChildren && !collapsed.has(row.id)) toggle(row.id);
        else {
          const p = parentOf(editor.tree, row.id);
          if (p) focusRow(list.find((x) => x.id === p));
        }
        break;
      case 'Enter':
      case ' ': e.preventDefault(); if (row) editor.select(row.id); break;
    }
  }

  function label(row) {
    if (row.type === 'text') return 'Text';
    if (row.type === 'image') return 'Image';
    if (row.type === 'button') return 'Button';
    if (row.type === 'link') return 'Link';
    if (row.type === 'embed') return 'Embed';
    if (row.type === 'component-ref') return editor.componentName(row.compId) || 'Component';
    return 'Container';
  }
</script>

<div class="layers">
  <ul
    class="tree"
    role="tree"
    aria-label="Layers"
    bind:this={treeEl}
    onclick={onTreeClick}
    oncontextmenu={onTreeContext}
    onkeydown={onTreeKeydown}
  >
    {#each rows as row (row.id)}
      {@const selected = editor.selectedId === row.id}
      <li role="none" style="--depth:{row.depth}">
        <div
          class="row"
          class:selected
          role="treeitem"
          data-layer-id={row.id}
          aria-level={row.depth + 1}
          aria-selected={selected}
          aria-expanded={row.hasChildren ? !collapsed.has(row.id) : undefined}
          tabindex={selected || (editor.selectedId === null && row.depth === 0) ? 0 : -1}
        >
          <span class="caret-slot">
            {#if row.hasChildren}
              <span
                class="caret"
                class:open={!collapsed.has(row.id)}
                data-caret={row.id}
                aria-hidden="true"
              >
                <ChevronRight size={13} />
              </span>
            {/if}
          </span>
          <span class="badge" data-group={groupFor(row)}>{row.type === 'component-ref' ? 'cmp' : row.tag}</span>
          <span class="type">{label(row)}</span>
          {#if row.classes.length}
            <span class="classes">.{row.classes.join(' .')}</span>
          {/if}
        </div>
      </li>
    {/each}
  </ul>
</div>

<style>
  .layers {
    flex: 1;
    min-height: 0;
    display: flex;
    flex-direction: column;
    overflow: hidden;
  }

  .tree {
    list-style: none;
    margin: 0;
    padding: 8px 8px 16px;
    overflow-y: auto;
    flex: 1;
  }

  .row {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 5px 8px;
    padding-left: calc(4px + var(--depth) * 14px);
    border-radius: 6px;
    cursor: pointer;
    color: var(--sec);
    font-size: 13px;
    user-select: none;
    scroll-margin: 32px 0;
  }

  .row:hover {
    background: var(--hover);
    color: var(--text);
  }

  .row.selected {
    background: var(--sidebar-bg-active);
    color: var(--text);
  }

  .row:focus-visible {
    outline: 2px solid var(--purple);
    outline-offset: -2px;
  }

  .caret-slot {
    width: 14px;
    display: inline-flex;
    justify-content: center;
    flex-shrink: 0;
  }

  .caret {
    display: inline-flex;
    color: var(--dim);
    transition: transform 0.15s;
    cursor: pointer;
  }

  .caret.open {
    transform: rotate(90deg);
  }

  .badge {
    font-family: var(--font-mono, ui-monospace, monospace);
    font-size: 10px;
    font-weight: 600;
    line-height: 1;
    padding: 3px 5px;
    border-radius: 4px;
    color: #fff;
    flex-shrink: 0;
  }

  .badge[data-group='structural'] { background: var(--blue); }
  .badge[data-group='heading'] { background: var(--purple); }
  .badge[data-group='text'] { background: var(--green); }
  .badge[data-group='media'] { background: var(--pink); }
  .badge[data-group='interactive'] { background: var(--amber); color: #1a1a1a; }
  .badge[data-group='list'] { background: #2dd4bf; color: #1a1a1a; }
  .badge[data-group='generic'] { background: var(--dim); }
  .badge[data-group='component'] { background: var(--purple); }

  .type {
    flex-shrink: 0;
  }

  .classes {
    color: var(--green);
    font-size: 12px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    margin-left: auto;
  }
</style>
