<script>
  import { Type, Image as ImageIcon, MousePointerClick, Link as LinkIcon, Zap } from 'lucide-svelte';

  let { editor } = $props();

  const ICONS = { text: Type, image: ImageIcon, button: MousePointerClick, link: LinkIcon };

  let items = $derived.by(() => {
    const tree = editor.tree;
    const out = [];
    const walk = (id) => {
      const n = tree.nodes[id];
      if (!n) return;
      if (n.type === 'text' || n.type === 'image' || n.type === 'button' || n.type === 'link') {
        out.push({
          id,
          type: n.type,
          field: n.props?.field || null,
          label: n.type === 'image' ? (n.props?.alt || 'Image') : (n.props?.text || n.tag),
        });
      }
      for (const c of n.children || []) walk(c);
    };
    walk(tree.root);
    return out;
  });
</script>

<div class="content-panel">
  <p class="intro">Edit the page's content. Layout and styling are locked. Items marked <Zap size={11} aria-hidden="true" /> are dynamic.</p>
  <ul class="list" aria-label="Editable content">
    {#each items as item (item.id)}
      {@const Icon = ICONS[item.type]}
      <li>
        <button class="row" class:selected={editor.selectedId === item.id} onclick={() => editor.select(item.id)}>
          <Icon size={15} aria-hidden="true" />
          <span class="label">{item.label}</span>
          {#if item.field}
            <span class="island" title="Dynamic field: {item.field}"><Zap size={12} aria-hidden="true" /></span>
          {/if}
        </button>
      </li>
    {/each}
    {#if items.length === 0}
      <li class="empty">No editable content on this page yet.</li>
    {/if}
  </ul>
</div>

<style>
  .content-panel { flex: 1; min-height: 0; display: flex; flex-direction: column; overflow: hidden; }
  .intro {
    font-size: 12px;
    color: var(--dim);
    line-height: 1.5;
    margin: 0;
    padding: 14px 14px 10px;
  }
  .intro :global(svg) { vertical-align: -1px; color: var(--amber); }
  .list { list-style: none; margin: 0; padding: 0 8px 16px; overflow-y: auto; flex: 1; }
  .row {
    display: flex;
    align-items: center;
    gap: 10px;
    width: 100%;
    padding: 9px 10px;
    border: none;
    border-radius: 7px;
    background: none;
    color: var(--sec);
    font-size: 13px;
    text-align: left;
    cursor: pointer;
  }
  .row:hover { background: var(--hover); color: var(--text); }
  .row.selected { background: var(--sidebar-bg-active); color: var(--text); }
  .row:focus-visible { outline: 2px solid var(--purple); outline-offset: -2px; }
  .label {
    flex: 1;
    min-width: 0;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }
  .island { display: inline-flex; color: var(--amber); flex-shrink: 0; }
  .empty { padding: 16px 10px; font-size: 12px; color: var(--dim); }
</style>
