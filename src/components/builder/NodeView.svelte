<script>
  import NodeView from './NodeView.svelte';

  let { node, editor } = $props();
  let selected = $derived(editor.selectedId === node.id);
  let cls = $derived(node.classes.length ? node.classes.join(' ') : undefined);
</script>

{#if node.type === 'image'}
  <svelte:element
    this={'img'}
    data-node-id={node.id}
    data-selected={selected || undefined}
    class={cls}
    src={node.props.src || ''}
    alt={node.props.alt || ''}
  />
{:else if node.type === 'container'}
  <svelte:element this={node.tag} data-node-id={node.id} data-selected={selected || undefined} class={cls}>
    {#each node.children as cid (cid)}
      {#if editor.tree.nodes[cid]}
        <NodeView node={editor.tree.nodes[cid]} {editor} />
      {/if}
    {/each}
  </svelte:element>
{:else}
  <svelte:element this={node.tag} data-node-id={node.id} data-selected={selected || undefined} class={cls}
    >{node.props.text || ''}</svelte:element>
{/if}
