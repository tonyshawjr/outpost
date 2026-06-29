<script>
  import NodeView from './NodeView.svelte';

  let { editor, oncontext } = $props();
  let surfaceEl = $state(null);

  $effect(() => {
    const el = surfaceEl;
    if (!el) return;
    const onClick = (e) => {
      const target = e.target.closest('[data-node-id]');
      editor.select(target ? target.getAttribute('data-node-id') : null);
    };
    const onCtx = (e) => {
      const target = e.target.closest('[data-node-id]');
      if (!target) return;
      e.preventDefault();
      const id = target.getAttribute('data-node-id');
      editor.select(id);
      oncontext?.(id, e.clientX, e.clientY);
    };
    el.addEventListener('click', onClick);
    el.addEventListener('contextmenu', onCtx);
    return () => {
      el.removeEventListener('click', onClick);
      el.removeEventListener('contextmenu', onCtx);
    };
  });

  $effect(() => {
    const el = document.createElement('style');
    el.textContent = editor.classesCss;
    document.head.appendChild(el);
    return () => el.remove();
  });

  let root = $derived(editor.tree?.nodes?.[editor.tree.root]);
</script>

<div class="canvas">
  <div class="frame">
    <div class="surface oc-canvas" bind:this={surfaceEl}>
      {#if root}
        <NodeView node={root} tree={editor.tree} {editor} />
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

  .surface :global([data-component-ref]) {
    outline: 1px dashed var(--purple-soft);
    outline-offset: 1px;
  }
  .surface :global([data-component-ref][data-selected]) {
    outline: 2px solid var(--purple);
  }

  .surface :global(img) {
    max-width: 100%;
  }
</style>
