<script>
  import { X, Loader2 } from 'lucide-svelte';
  import { SECTION_PATTERNS, SECTION_CATEGORIES } from '$lib/section-patterns.js';

  let { editor, parentId = null, onclose, onimported } = $props();

  let category = $state('All');
  let busyId = $state('');
  let error = $state('');
  let dialogEl = $state(null);

  let shown = $derived(
    category === 'All' ? SECTION_PATTERNS : SECTION_PATTERNS.filter((p) => p.category === category)
  );

  function frameDoc(p) {
    return `<!doctype html><html><head><meta charset="utf-8"><style>html,body{margin:0;background:#fff}${p.css}</style></head><body>${p.html}</body></html>`;
  }

  async function insert(p) {
    if (busyId) return;
    busyId = p.id;
    error = '';
    try {
      const res = await editor.importSection(p.html, p.css, p.js || '', parentId);
      onimported?.(res);
      onclose?.();
    } catch (e) {
      error = e?.message || 'Could not add that section.';
      busyId = '';
    }
  }

  function onKey(e) {
    if (e.key === 'Escape') { e.stopPropagation(); onclose?.(); }
  }

  $effect(() => { dialogEl?.focus(); });
</script>

<div class="overlay" role="presentation" onclick={(e) => { if (e.target === e.currentTarget) onclose?.(); }}>
  <div class="dialog" bind:this={dialogEl} tabindex="-1" role="dialog" aria-modal="true" aria-labelledby="sg-title" onkeydown={onKey}>
    <header class="head">
      <div>
        <h2 id="sg-title">Add a section</h2>
        <p class="sub">Pick a pre-designed section — it drops onto the canvas as editable elements you can restyle.</p>
      </div>
      <button class="close" onclick={() => onclose?.()} aria-label="Close">
        <X size={18} aria-hidden="true" />
      </button>
    </header>

    <div class="filters" role="tablist" aria-label="Section category">
      {#each SECTION_CATEGORIES as c (c)}
        <button role="tab" aria-selected={category === c} class:on={category === c} onclick={() => (category = c)}>{c}</button>
      {/each}
    </div>

    {#if error}<p class="error" role="alert">{error}</p>{/if}

    <div class="grid">
      {#each shown as p (p.id)}
        <div class="card">
          <span class="thumb">
            <iframe class="frame" srcdoc={frameDoc(p)} title={`${p.name} preview`} sandbox="" tabindex="-1" scrolling="no" loading="lazy"></iframe>
            {#if busyId === p.id}
              <span class="thumb-busy"><Loader2 size={18} class="spin" aria-hidden="true" /></span>
            {/if}
          </span>
          <span class="meta">
            <span class="name">{p.name}</span>
            <span class="cat">{p.category}</span>
          </span>
          <button class="card-hit" onclick={() => insert(p)} disabled={!!busyId} aria-label={`Add ${p.name} section (${p.category})`}></button>
        </div>
      {/each}
    </div>
  </div>
</div>

<style>
  .overlay { position: fixed; inset: 0; z-index: 200; display: flex; align-items: center; justify-content: center; padding: 24px; background: rgba(0, 0, 0, 0.5); }
  .dialog { display: flex; flex-direction: column; width: 100%; max-width: 940px; max-height: 90vh; background: var(--raised); border: 1px solid var(--border); border-radius: 14px; box-shadow: var(--shadow-lg, 0 24px 60px rgba(0, 0, 0, 0.35)); overflow: hidden; }
  .dialog:focus-visible { outline: none; }

  .head { display: flex; align-items: flex-start; gap: 16px; padding: 18px 20px 14px; border-bottom: 1px solid var(--border); }
  .head h2 { margin: 0 0 4px; font-size: 16px; font-weight: 600; color: var(--text); }
  .sub { margin: 0; font-size: 12.5px; color: var(--dim); line-height: 1.5; max-width: 60ch; }
  .close { margin-left: auto; display: inline-flex; padding: 6px; border: none; border-radius: 7px; background: transparent; color: var(--sec); cursor: pointer; flex-shrink: 0; }
  .close:hover { background: var(--hover); color: var(--text); }
  .close:focus-visible { outline: 2px solid var(--purple); outline-offset: 1px; }

  .filters { display: flex; gap: 4px; padding: 12px 16px; flex-shrink: 0; flex-wrap: wrap; }
  .filters button { padding: 6px 12px; border: none; border-radius: 999px; background: transparent; color: var(--sec); font-size: 12.5px; font-weight: 600; cursor: pointer; }
  .filters button.on { background: var(--purple); color: #fff; }
  .filters button:not(.on):hover { background: var(--hover); color: var(--text); }
  .filters button:focus-visible { outline: 2px solid var(--purple); outline-offset: 1px; }

  .error { margin: 0 16px 8px; padding: 9px 12px; border-radius: 8px; background: var(--red-bg, rgba(220, 60, 60, 0.12)); color: var(--red, #d64545); font-size: 12.5px; }

  .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(264px, 1fr)); gap: 14px; padding: 4px 16px 18px; overflow-y: auto; }

  .card { position: relative; display: flex; flex-direction: column; padding: 0; border: 1px solid var(--border); border-radius: 12px; background: var(--bg); overflow: hidden; text-align: left; }
  .card:hover { border-color: var(--purple); }
  .card-hit { position: absolute; inset: 0; border: none; background: transparent; padding: 0; cursor: pointer; border-radius: 12px; }
  .card-hit:disabled { cursor: default; }
  .card-hit:focus-visible { outline: 2px solid var(--purple); outline-offset: 2px; }

  .thumb { position: relative; display: block; height: 168px; overflow: hidden; background: #fff; border-bottom: 1px solid var(--border); }
  .frame { width: 1056px; height: 672px; border: 0; transform: scale(0.25); transform-origin: top left; pointer-events: none; }
  .thumb-busy { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; background: rgba(255, 255, 255, 0.6); color: var(--purple); }
  .thumb-busy :global(.spin) { animation: sg-spin 0.8s linear infinite; }

  .meta { display: flex; align-items: baseline; justify-content: space-between; gap: 8px; padding: 10px 12px; }
  .name { font-size: 13px; font-weight: 600; color: var(--text); }
  .cat { font-size: 11px; color: var(--dim); text-transform: uppercase; letter-spacing: 0.04em; }

  @keyframes sg-spin { to { transform: rotate(360deg); } }
</style>
