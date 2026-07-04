<script>
  import NodeView from './NodeView.svelte';

  let { node, tree, editor, inert = false, preview = false } = $props();
  let selected = $derived(!inert && editor.selectedId === node.id);
  let cls = $derived(node.classes.length ? node.classes.join(' ') : undefined);
  let nid = $derived(inert ? undefined : node.id);
  let fieldName = $derived(!inert ? (node.props.field || null) : null);
  let fieldVal = $derived(fieldName ? editor.fieldValue(fieldName) : null);
  let hasFieldVal = $derived(fieldVal != null && fieldVal !== '');
  let text = $derived(hasFieldVal ? fieldVal : (node.props.text || ''));
  let imgSrc = $derived(hasFieldVal ? fieldVal : (node.props.src || ''));
  let loopLabel = $derived(
    node.type === 'loop'
      ? (node.props.collection
          ? `Loop · ${node.props.collection}${node.props.limit ? ` ×${node.props.limit}` : ''}`
          : 'Loop · pick a collection')
      : ''
  );
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
{:else if node.type === 'embed'}
  <div data-node-id={nid} data-selected={selected || undefined} class={cls}>
    {#if node.props.kind === 'photo' && node.props.embedUrl}
      <span class="oc-embed oc-embed--photo"><img src={node.props.embedUrl} alt={node.props.title || ''} width={node.props.width || 16} height={node.props.height || 9} /></span>
    {:else if node.props.embedUrl}
      <span class="oc-embed"><iframe src={node.props.embedUrl} title={node.props.title || 'Embedded media'} width={node.props.width || 16} height={node.props.height || 9} loading="lazy"></iframe></span>
    {:else}
      <span class="oc-embed-empty">Embed</span>
    {/if}
  </div>
{:else if node.type === 'loop'}
  <svelte:element this={node.tag} data-node-id={nid} data-selected={selected || undefined} data-loop={preview ? undefined : true} class={cls}>
    {#if !inert && !preview}<span class="oc-loop-badge">{loopLabel}</span>{/if}
    {#each node.children as cid (cid)}
      {#if tree.nodes[cid]}
        <NodeView node={tree.nodes[cid]} {tree} {editor} {inert} {preview} />
      {/if}
    {/each}
  </svelte:element>
{:else if node.type === 'container'}
  <svelte:element this={node.tag} data-node-id={nid} data-selected={selected || undefined} data-empty={!inert && !preview && node.children.length === 0 ? true : undefined} class={cls}>
    {#each node.children as cid (cid)}
      {#if tree.nodes[cid]}
        <NodeView node={tree.nodes[cid]} {tree} {editor} {inert} {preview} />
      {/if}
    {/each}
  </svelte:element>
{:else}
  <svelte:element this={node.tag} data-node-id={nid} data-selected={selected || undefined} data-field={fieldName || undefined} class={cls}
    >{text}</svelte:element>
{/if}
