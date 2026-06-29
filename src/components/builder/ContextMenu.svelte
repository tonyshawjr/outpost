<script>
  let { x, y, items, onclose } = $props();
  let menuEl = $state(null);

  $effect(() => {
    const onDoc = (e) => { if (menuEl && !menuEl.contains(e.target)) onclose(); };
    const onKey = (e) => { if (e.key === 'Escape') onclose(); };
    document.addEventListener('mousedown', onDoc);
    document.addEventListener('keydown', onKey);
    requestAnimationFrame(() => menuEl?.querySelector('button')?.focus());
    return () => {
      document.removeEventListener('mousedown', onDoc);
      document.removeEventListener('keydown', onKey);
    };
  });

  function run(item) {
    onclose();
    item.action();
  }
</script>

<div class="ctx" role="menu" bind:this={menuEl} style="left:{x}px; top:{y}px">
  {#each items as item (item.label)}
    {#if item.divider}
      <div class="ctx-divider" role="separator"></div>
    {:else}
      <button role="menuitem" class="ctx-item" class:danger={item.danger} disabled={item.disabled} onclick={() => run(item)}>
        {item.label}
      </button>
    {/if}
  {/each}
</div>

<style>
  .ctx {
    position: fixed;
    z-index: 300;
    min-width: 190px;
    padding: 5px;
    background: var(--raised);
    border: 1px solid var(--border);
    border-radius: 9px;
    box-shadow: var(--shadow-lg);
  }
  .ctx-item {
    display: block;
    width: 100%;
    padding: 8px 10px;
    border: none;
    border-radius: 6px;
    background: none;
    color: var(--text);
    font-size: 13px;
    text-align: left;
    cursor: pointer;
  }
  .ctx-item:hover:not(:disabled) { background: var(--hover); }
  .ctx-item:disabled { opacity: 0.4; cursor: default; }
  .ctx-item:focus-visible { outline: 2px solid var(--purple); outline-offset: -2px; }
  .ctx-item.danger { color: var(--red); }
  .ctx-divider { height: 1px; background: var(--border); margin: 4px 2px; }
</style>
