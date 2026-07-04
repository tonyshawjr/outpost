<script>
  let { items, onclose } = $props();

  let query = $state('');
  let active = $state(0);
  let inputEl = $state(null);

  let filtered = $derived(
    query.trim() === ''
      ? items
      : items.filter((it) => (it.label + ' ' + (it.keywords || '')).toLowerCase().includes(query.trim().toLowerCase()))
  );

  $effect(() => { inputEl?.focus(); });
  $effect(() => { if (active >= filtered.length) active = Math.max(0, filtered.length - 1); });

  function choose(it) {
    if (!it) return;
    onclose?.();
    it.run();
  }

  function onKey(e) {
    if (e.key === 'Escape') { e.preventDefault(); onclose?.(); }
    else if (e.key === 'ArrowDown') { e.preventDefault(); active = Math.min(active + 1, filtered.length - 1); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); active = Math.max(active - 1, 0); }
    else if (e.key === 'Enter') { e.preventDefault(); choose(filtered[active]); }
  }
</script>

<div class="overlay" role="presentation" onclick={(e) => { if (e.target === e.currentTarget) onclose?.(); }}>
  <div class="palette" role="dialog" aria-modal="true" aria-label="Insert element">
    <input
      bind:this={inputEl}
      class="q"
      type="text"
      bind:value={query}
      onkeydown={onKey}
      placeholder="Add an element…"
      aria-label="Search elements to insert"
      aria-controls="qi-list"
      autocomplete="off"
      spellcheck="false"
    />
    <ul class="list" id="qi-list" role="listbox" aria-label="Elements">
      {#each filtered as it, i (it.label)}
        <li>
          <button
            class="item"
            class:active={i === active}
            role="option"
            aria-selected={i === active}
            onmousemove={() => (active = i)}
            onclick={() => choose(it)}
          >
            {#if it.icon}<span class="ico"><it.icon size={15} aria-hidden="true" /></span>{/if}
            <span class="label">{it.label}</span>
            {#if it.hint}<span class="hint">{it.hint}</span>{/if}
          </button>
        </li>
      {/each}
      {#if filtered.length === 0}
        <li class="empty">No matching elements</li>
      {/if}
    </ul>
  </div>
</div>

<style>
  .overlay { position: fixed; inset: 0; z-index: 210; display: flex; align-items: flex-start; justify-content: center; padding: 12vh 24px 24px; background: rgba(0, 0, 0, 0.4); }
  .palette { width: 100%; max-width: 440px; background: var(--raised); border: 1px solid var(--border); border-radius: 12px; box-shadow: var(--shadow-lg, 0 24px 60px rgba(0, 0, 0, 0.35)); overflow: hidden; }
  .q { width: 100%; padding: 14px 16px; border: none; border-bottom: 1px solid var(--border); background: transparent; color: var(--text); font-size: 15px; }
  .q:focus { outline: none; }
  .q::placeholder { color: var(--dim); }
  .list { list-style: none; margin: 0; padding: 6px; max-height: 320px; overflow-y: auto; }
  .item { display: flex; align-items: center; gap: 10px; width: 100%; padding: 9px 10px; border: none; border-radius: 8px; background: transparent; color: var(--text); font-size: 13.5px; text-align: left; cursor: pointer; }
  .item.active { background: var(--purple); color: #fff; }
  .item.active .hint, .item.active .ico { color: rgba(255, 255, 255, 0.85); }
  .ico { display: inline-flex; color: var(--sec); }
  .label { flex: 1; font-weight: 500; }
  .hint { font-size: 11.5px; color: var(--dim); }
  .empty { padding: 14px 12px; color: var(--dim); font-size: 13px; text-align: center; }
</style>
