<script>
  import Checkbox from '$components/Checkbox.svelte';
  import { Play } from 'lucide-svelte';

  let { editor, selected } = $props();

  let m = $derived(selected.props.motion || {});
  let trigger = $derived(m.trigger || 'none');
  let effect = $derived(m.effect || 'fade');

  const TRIGGERS = [
    { value: 'none', label: 'None' },
    { value: 'reveal', label: 'Reveal on scroll' },
    { value: 'scroll', label: 'Animate with scroll' },
    { value: 'click', label: 'Replay on click' },
  ];
  const EFFECTS = [
    { value: 'fade', label: 'Fade' },
    { value: 'slide-up', label: 'Slide up' },
    { value: 'slide-down', label: 'Slide down' },
    { value: 'slide-left', label: 'Slide left' },
    { value: 'slide-right', label: 'Slide right' },
    { value: 'scale', label: 'Scale' },
  ];

  function patch(changes) {
    const next = { ...m, ...changes };
    editor.updateProps(selected.id, { motion: next });
  }
  function setTrigger(e) {
    const v = e.target.value;
    if (v === 'none') { editor.updateProps(selected.id, { motion: null }); return; }
    const base = { trigger: v, effect: m.effect || 'fade' };
    if (v !== 'scroll') { base.duration = m.duration || 600; base.delay = m.delay || 0; }
    if (v === 'reveal') base.once = m.once !== false;
    if (m.distance) base.distance = m.distance;
    editor.updateProps(selected.id, { motion: base });
  }
  function num(key, e) {
    const n = parseInt(e.target.value, 10);
    patch({ [key]: Number.isNaN(n) ? 0 : n });
  }
  function preview() {
    window.dispatchEvent(new CustomEvent('outpost:motion-preview', { detail: { id: selected.id } }));
  }
</script>

<div class="ix">
  <label class="fld">
    <span class="fld-label">Interaction</span>
    <select value={trigger} onchange={setTrigger}>
      {#each TRIGGERS as t (t.value)}<option value={t.value}>{t.label}</option>{/each}
    </select>
  </label>

  {#if trigger !== 'none'}
    <label class="fld">
      <span class="fld-label">Effect</span>
      <select value={effect} onchange={(e) => patch({ effect: e.target.value })}>
        {#each EFFECTS as ef (ef.value)}<option value={ef.value}>{ef.label}</option>{/each}
      </select>
    </label>

    {#if effect !== 'fade'}
      <label class="fld">
        <span class="fld-label">Distance (px)</span>
        <input type="number" min="0" max="400" value={m.distance ?? (trigger === 'scroll' ? 40 : 24)} oninput={(e) => num('distance', e)} />
      </label>
    {/if}

    {#if trigger !== 'scroll'}
      <div class="row">
        <label class="fld">
          <span class="fld-label">Duration (ms)</span>
          <input type="number" min="0" max="5000" step="50" value={m.duration ?? 600} oninput={(e) => num('duration', e)} />
        </label>
        <label class="fld">
          <span class="fld-label">Delay (ms)</span>
          <input type="number" min="0" max="5000" step="50" value={m.delay ?? 0} oninput={(e) => num('delay', e)} />
        </label>
      </div>
    {/if}

    {#if trigger === 'reveal'}
      <div class="opt"><Checkbox checked={m.once !== false} label="Play once" onchange={(v) => patch({ once: v })} /></div>
    {/if}

    {#if trigger === 'reveal' || trigger === 'click'}
      <button class="play" type="button" onclick={preview}>
        <Play size={14} aria-hidden="true" />
        <span>Play preview</span>
      </button>
    {:else}
      <p class="hint">Scrubs with the scroll position. See it on your live page.</p>
    {/if}
  {/if}
</div>

<style>
  .ix { display: flex; flex-direction: column; gap: 12px; }
  .fld { display: flex; flex-direction: column; gap: 5px; }
  .fld-label { font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; color: var(--dim); }
  .fld select, .fld input { width: 100%; padding: 8px 10px; border: 1px solid var(--border); border-radius: 8px; background: var(--raised); color: var(--text); font-size: 13px; }
  .fld select:focus-visible, .fld input:focus-visible { outline: 2px solid var(--purple); outline-offset: 1px; border-color: var(--purple); }
  .row { display: flex; gap: 10px; }
  .row .fld { flex: 1; }
  .opt { padding: 2px 0; }
  .play { display: inline-flex; align-items: center; gap: 6px; align-self: flex-start; padding: 7px 12px; border: 1px solid var(--border); border-radius: 8px; background: transparent; color: var(--text); font-size: 12.5px; font-weight: 600; cursor: pointer; }
  .play:hover { background: var(--hover); border-color: var(--purple); }
  .play:focus-visible { outline: 2px solid var(--purple); outline-offset: 1px; }
  .hint { font-size: 12px; color: var(--dim); line-height: 1.5; margin: 0; }
</style>
