<script>
  import NodeView from './NodeView.svelte';

  let { editor } = $props();
  let surfaceEl = $state(null);

  $effect(() => {
    const el = surfaceEl;
    if (!el) return;
    const onClick = (e) => {
      const target = e.target.closest('[data-node-id]');
      editor.select(target ? target.getAttribute('data-node-id') : null);
    };
    el.addEventListener('click', onClick);
    return () => el.removeEventListener('click', onClick);
  });

  let root = $derived(editor.tree?.nodes?.[editor.tree.root]);
</script>

<div class="canvas">
  <div class="frame">
    <div class="surface" bind:this={surfaceEl}>
      {#if root}
        <NodeView node={root} {editor} />
      {/if}
    </div>
  </div>
</div>

<style>
  .canvas {
    flex: 1;
    min-width: 0;
    overflow: auto;
    display: flex;
    justify-content: center;
    padding: 32px;
    background: var(--bg);
  }

  .frame {
    width: 100%;
    max-width: 1100px;
    align-self: flex-start;
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    background: #ffffff;
    box-shadow: var(--shadow-md);
    overflow: hidden;
  }

  .surface {
    min-height: 420px;
    color: #111;
  }

  .surface :global([data-node-id]) {
    cursor: pointer;
  }

  .surface :global([data-node-id][data-selected]) {
    outline: 2px solid var(--purple);
    outline-offset: 1px;
  }

  .surface :global(img) {
    max-width: 100%;
  }
</style>
