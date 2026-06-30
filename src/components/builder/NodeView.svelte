<script>
  import NodeView from './NodeView.svelte';

  let { node, tree, editor, inert = false } = $props();
  let selected = $derived(!inert && editor.selectedId === node.id);
  let cls = $derived(node.classes.length ? node.classes.join(' ') : undefined);
  let nid = $derived(inert ? undefined : node.id);
  let fieldName = $derived(!inert ? (node.props.field || null) : null);
  let fieldVal = $derived(fieldName ? editor.fieldValue(fieldName) : null);
  let hasFieldVal = $derived(fieldVal != null && fieldVal !== '');
  let text = $derived(hasFieldVal ? fieldVal : (node.props.text || ''));
  let imgSrc = $derived(hasFieldVal ? fieldVal : (node.props.src || ''));
</script>

{#if node.type === 'component-ref'}
  {@const comp = editor.components[node.props.componentId]}
  <div data-node-id={nid} data-selected={selected || undefined} data-component-ref class={cls}>
    {#if comp && comp.tree.nodes[comp.tree.root]}
      <NodeView node={comp.tree.nodes[comp.tree.root]} tree={comp.tree} {editor} inert />
    {/if}
  </div>
{:else if node.type === 'image'}
  <svelte:element
    this={'img'}
    data-node-id={nid}
    data-selected={selected || undefined}
    data-field={fieldName || undefined}
    class={cls}
    src={imgSrc}
    alt={node.props.alt || ''}
  />
{:else if node.type === 'container'}
  <svelte:element this={node.tag} data-node-id={nid} data-selected={selected || undefined} class={cls}>
    {#each node.children as cid (cid)}
      {#if tree.nodes[cid]}
        <NodeView node={tree.nodes[cid]} {tree} {editor} {inert} />
      {/if}
    {/each}
  </svelte:element>
{:else}
  <svelte:element this={node.tag} data-node-id={nid} data-selected={selected || undefined} data-field={fieldName || undefined} class={cls}
    >{text}</svelte:element>
{/if}
