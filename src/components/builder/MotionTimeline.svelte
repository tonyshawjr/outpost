<script>
  import { X, Play, AlignHorizontalDistributeCenter } from 'lucide-svelte';

  let { editor, onclose } = $props();

  function collect(tree) {
    const out = [];
    const walk = (id) => {
      const n = tree.nodes[id];
      if (!n) return;
      const m = n.props?.motion;
      if (m && m.trigger && m.trigger !== 'scroll') {
        out.push({ id, motion: m, label: `${n.tag}${n.classes?.[0] ? '.' + n.classes[0] : n.props?.text ? ' · ' + String(n.props.text).slice(0, 14) : ''}` });
      }
      (n.children || []).forEach(walk);
    };
    walk(tree.root);
    return out;
  }

  let rows = $derived(collect(editor.tree));
  let maxTime = $derived(Math.max(1000, ...rows.map((r) => (r.motion.delay || 0) + (r.motion.duration || 600)), 0) + 200);
  const PX_PER_MS = 0.16;
  let width = $derived(maxTime * PX_PER_MS);
  let ticks = $derived(Array.from({ length: Math.floor(maxTime / 250) + 1 }, (_, i) => i * 250));

  let drag = $state(null);

  function barDelay(r) { return drag && drag.id === r.id ? drag.delay : (r.motion.delay || 0); }

  function onDown(e, r) {
    e.preventDefault();
    drag = { id: r.id, startX: e.clientX, startDelay: r.motion.delay || 0, delay: r.motion.delay || 0 };
    window.addEventListener('pointermove', onMove);
    window.addEventListener('pointerup', onUp);
  }
  function onMove(e) {
    if (!drag) return;
    const d = Math.round(((e.clientX - drag.startX) / PX_PER_MS + drag.startDelay) / 10) * 10;
    drag = { ...drag, delay: Math.max(0, Math.min(5000, d)) };
  }
  function onUp() {
    if (drag) {
      const r = rows.find((x) => x.id === drag.id);
      if (r) editor.updateProps(drag.id, { motion: { ...r.motion, delay: drag.delay } });
    }
    drag = null;
    window.removeEventListener('pointermove', onMove);
    window.removeEventListener('pointerup', onUp);
  }

  function autoStagger() {
    const reveals = rows.filter((r) => r.motion.trigger === 'reveal');
    reveals.forEach((r, i) => editor.updateProps(r.id, { motion: { ...r.motion, delay: i * 120 } }));
  }
  function playAll() {
    rows.forEach((r) => window.dispatchEvent(new CustomEvent('outpost:motion-preview', { detail: { id: r.id } })));
  }
  function setDelay(r, e) {
    const n = Math.max(0, Math.min(5000, parseInt(e.target.value, 10) || 0));
    editor.updateProps(r.id, { motion: { ...r.motion, delay: n } });
  }
</script>

<div class="tl">
  <div class="tl-head">
    <span class="tl-title">Timeline</span>
    <span class="tl-count">{rows.length} animated {rows.length === 1 ? 'element' : 'elements'}</span>
    <div class="tl-actions">
      <button class="tl-btn" onclick={autoStagger} disabled={rows.filter((r) => r.motion.trigger === 'reveal').length < 2} title="Stagger reveal delays evenly">
        <AlignHorizontalDistributeCenter size={14} aria-hidden="true" /><span>Auto-stagger</span>
      </button>
      <button class="tl-btn" onclick={playAll} disabled={rows.length === 0}>
        <Play size={14} aria-hidden="true" /><span>Play all</span>
      </button>
      <button class="tl-close" onclick={onclose} aria-label="Close timeline"><X size={16} aria-hidden="true" /></button>
    </div>
  </div>

  {#if rows.length === 0}
    <p class="tl-empty">No animations yet. Select an element and add an interaction to see it here.</p>
  {:else}
    <div class="tl-body">
      <div class="tl-ruler" style="width:{width}px">
        {#each ticks as t (t)}
          <span class="tick" style="left:{t * PX_PER_MS}px">{t >= 1000 ? (t / 1000) + 's' : t}</span>
        {/each}
      </div>
      {#each rows as r (r.id)}
        <div class="tl-row">
          <button class="tl-label" onclick={() => editor.select(r.id)} title="Select {r.label}">{r.label}</button>
          <div class="tl-track" style="width:{width}px">
            <button
              class="tl-bar"
              class:click={r.motion.trigger === 'click'}
              style="left:{barDelay(r) * PX_PER_MS}px; width:{Math.max(14, (r.motion.duration || 600) * PX_PER_MS)}px"
              onpointerdown={(e) => onDown(e, r)}
              aria-label="{r.label} delay {barDelay(r)}ms — drag to change"
            >{r.motion.effect}</button>
          </div>
          <input class="tl-delay" type="number" min="0" max="5000" step="10" value={r.motion.delay || 0} oninput={(e) => setDelay(r, e)} aria-label="{r.label} delay in ms" />
        </div>
      {/each}
    </div>
  {/if}
</div>

<style>
  .tl { border-top: 1px solid var(--border); background: var(--raised); max-height: 42vh; display: flex; flex-direction: column; flex-shrink: 0; }
  .tl-head { display: flex; align-items: center; gap: 12px; padding: 8px 14px; border-bottom: 1px solid var(--border); }
  .tl-title { font-size: 12px; font-weight: 700; color: var(--text); }
  .tl-count { font-size: 11.5px; color: var(--dim); }
  .tl-actions { margin-left: auto; display: flex; align-items: center; gap: 6px; }
  .tl-btn { display: inline-flex; align-items: center; gap: 6px; padding: 5px 10px; border: 1px solid var(--border); border-radius: 7px; background: transparent; color: var(--sec); font-size: 12px; font-weight: 600; cursor: pointer; }
  .tl-btn:hover:not(:disabled) { background: var(--hover); color: var(--text); }
  .tl-btn:disabled { opacity: 0.4; cursor: default; }
  .tl-btn:focus-visible { outline: 2px solid var(--purple); outline-offset: 1px; }
  .tl-close { display: inline-flex; padding: 5px; border: none; border-radius: 6px; background: transparent; color: var(--sec); cursor: pointer; }
  .tl-close:hover { background: var(--hover); color: var(--text); }
  .tl-close:focus-visible { outline: 2px solid var(--purple); outline-offset: 1px; }

  .tl-empty { padding: 22px 16px; color: var(--dim); font-size: 13px; text-align: center; margin: 0; }
  .tl-body { overflow: auto; padding: 8px 14px 14px; }
  .tl-ruler { position: relative; height: 18px; margin-left: 180px; }
  .tick { position: absolute; top: 0; font-size: 10px; color: var(--dim); border-left: 1px solid var(--border); padding-left: 3px; height: 100%; }

  .tl-row { display: flex; align-items: center; gap: 0; margin-top: 6px; }
  .tl-label { width: 168px; flex-shrink: 0; padding: 6px 10px; margin-right: 12px; border: 1px solid var(--border); border-radius: 7px; background: var(--bg); color: var(--text); font-size: 12px; text-align: left; cursor: pointer; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .tl-label:hover { border-color: var(--purple); }
  .tl-label:focus-visible { outline: 2px solid var(--purple); outline-offset: 1px; }
  .tl-track { position: relative; height: 30px; flex-shrink: 0; background: var(--bg); border-radius: 7px; }
  .tl-bar { position: absolute; top: 3px; height: 24px; display: inline-flex; align-items: center; padding: 0 8px; border: none; border-radius: 6px; background: var(--purple); color: #fff; font-size: 10.5px; font-weight: 600; cursor: ew-resize; overflow: hidden; white-space: nowrap; touch-action: none; }
  .tl-bar.click { background: #10b981; color: #04140d; }
  .tl-bar:focus-visible { outline: 2px solid #fff; outline-offset: -3px; }
  .tl-delay { width: 68px; flex-shrink: 0; margin-left: 12px; padding: 6px 8px; border: 1px solid var(--border); border-radius: 7px; background: var(--bg); color: var(--text); font-size: 12px; }
  .tl-delay:focus-visible { outline: 2px solid var(--purple); outline-offset: 1px; }
</style>
