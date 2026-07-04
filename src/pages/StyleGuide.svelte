<script>
  import { onMount } from 'svelte';
  import { designTokens as tokensApi } from '$lib/api.js';
  import { addToast } from '$lib/stores.js';
  import { defaultTokens, colorScale, typeScale, spacingScale, colorNameValid, COLOR_STEPS, SCALE_STEPS } from '$lib/builder-tokens.js';
  import Checkbox from '$components/Checkbox.svelte';
  import { Plus, Trash2, Save } from 'lucide-svelte';

  let tokens = $state(defaultTokens());
  let loading = $state(true);
  let saving = $state(false);

  onMount(async () => {
    try {
      const res = await tokensApi.get();
      if (res.tokens && typeof res.tokens === 'object') {
        tokens = {
          colors: Array.isArray(res.tokens.colors) && res.tokens.colors.length ? res.tokens.colors : defaultTokens().colors,
          type: { ...defaultTokens().type, ...(res.tokens.type || {}) },
          spacing: { ...defaultTokens().spacing, ...(res.tokens.spacing || {}) },
        };
      }
    } catch { addToast('Could not load your style guide', 'error'); }
    finally { loading = false; }
  });

  let typePreview = $derived(typeScale(tokens.type));
  let spacePreview = $derived(spacingScale(tokens.spacing));

  function addColor() {
    let n = 1;
    let name = 'accent';
    const taken = new Set(tokens.colors.map((c) => c.name));
    while (taken.has(name)) { name = `accent-${n++}`; }
    tokens.colors = [...tokens.colors, { name, value: '#3b82f6', utilities: true }];
  }
  function removeColor(i) { tokens.colors = tokens.colors.filter((_, idx) => idx !== i); }
  function setColor(i, key, val) {
    tokens.colors = tokens.colors.map((c, idx) => (idx === i ? { ...c, [key]: val } : c));
  }

  function normHex(v) {
    let h = v.trim();
    if (h && h[0] !== '#') h = '#' + h;
    return h;
  }

  let dupNames = $derived.by(() => {
    const seen = {}; const dup = new Set();
    for (const c of tokens.colors) { if (seen[c.name]) dup.add(c.name); seen[c.name] = true; }
    return dup;
  });
  let valid = $derived(tokens.colors.every((c) => colorNameValid(c.name)) && dupNames.size === 0);

  async function save() {
    if (saving || !valid) return;
    saving = true;
    try {
      await tokensApi.save(tokens);
      addToast('Style guide saved — applied across your site', 'success');
    } catch (e) { addToast(e?.message || 'Save failed', 'error'); }
    finally { saving = false; }
  }
</script>

<div class="wrap">
  <header class="page-header">
    <div class="page-header-text">
      <h1 class="page-title">Style guide</h1>
      <p class="page-subtitle">Set your brand colors and scales once — they generate shades and apply as CSS variables across your whole site.</p>
    </div>
    <div class="page-header-actions">
      <button class="btn btn-primary" onclick={save} disabled={saving || !valid}>
        <Save size={15} aria-hidden="true" />
        <span>{saving ? 'Saving…' : 'Save'}</span>
      </button>
    </div>
  </header>

  {#if loading}
    <p class="muted">Loading…</p>
  {:else}
    <section class="block">
      <div class="block-head">
        <h2>Colors</h2>
        <button class="add-btn" onclick={addColor}><Plus size={14} aria-hidden="true" /><span>Add color</span></button>
      </div>
      <div class="colors">
        {#each tokens.colors as c, i (i)}
          {@const scale = colorScale(normHex(c.value))}
          <div class="color">
            <div class="color-row">
              <input class="swatch-input" type="color" value={normHex(c.value)} oninput={(e) => setColor(i, 'value', e.target.value)} aria-label={`${c.name} color`} />
              <label class="fld name-fld">
                <span class="fld-label">Name</span>
                <input type="text" value={c.name} oninput={(e) => setColor(i, 'name', e.target.value.toLowerCase())} spellcheck="false" aria-invalid={!colorNameValid(c.name) || dupNames.has(c.name)} />
              </label>
              <label class="fld hex-fld">
                <span class="fld-label">Hex</span>
                <input type="text" value={c.value} oninput={(e) => setColor(i, 'value', normHex(e.target.value))} spellcheck="false" />
              </label>
              <div class="util">
                <Checkbox checked={c.utilities} label="Utility classes" onchange={(v) => setColor(i, 'utilities', v)} />
              </div>
              <button class="icon-btn" onclick={() => removeColor(i)} aria-label={`Remove ${c.name}`} disabled={tokens.colors.length <= 1}>
                <Trash2 size={15} aria-hidden="true" />
              </button>
            </div>
            {#if !colorNameValid(c.name)}
              <p class="err" role="alert">Use lowercase letters, numbers and hyphens (must start with a letter).</p>
            {:else if dupNames.has(c.name)}
              <p class="err" role="alert">Duplicate name — each color needs a unique name.</p>
            {/if}
            {#if scale}
              <ol class="scale" aria-label={`${c.name} shades`}>
                {#each COLOR_STEPS as step (step)}
                  <li class="shade" style="background:{scale[step]}"><span class="shade-num">{step}</span></li>
                {/each}
              </ol>
            {/if}
          </div>
        {/each}
      </div>
    </section>

    <section class="block">
      <div class="block-head"><h2>Typography</h2></div>
      <div class="scale-controls">
        <label class="fld"><span class="fld-label">Base size (min px)</span><input type="number" min="10" max="32" bind:value={tokens.type.baseMin} /></label>
        <label class="fld"><span class="fld-label">Base size (max px)</span><input type="number" min="10" max="40" bind:value={tokens.type.baseMax} /></label>
        <label class="fld"><span class="fld-label">Ratio</span><input type="number" min="1.05" max="1.8" step="0.05" bind:value={tokens.type.ratio} /></label>
      </div>
      <ul class="type-preview">
        {#each [...SCALE_STEPS].reverse() as step (step)}
          <li><span class="type-tag">{step}</span><span class="type-sample" style="font-size:{typePreview[step]}">The quick brown fox</span></li>
        {/each}
      </ul>
    </section>

    <section class="block">
      <div class="block-head"><h2>Spacing</h2></div>
      <div class="scale-controls">
        <label class="fld"><span class="fld-label">Base (min px)</span><input type="number" min="4" max="32" bind:value={tokens.spacing.baseMin} /></label>
        <label class="fld"><span class="fld-label">Base (max px)</span><input type="number" min="4" max="40" bind:value={tokens.spacing.baseMax} /></label>
        <label class="fld"><span class="fld-label">Ratio</span><input type="number" min="1.1" max="2" step="0.05" bind:value={tokens.spacing.ratio} /></label>
      </div>
      <ul class="space-preview">
        {#each SCALE_STEPS as step (step)}
          <li><span class="space-tag">{step}</span><span class="space-bar" style="width:{spacePreview[step]}"></span></li>
        {/each}
      </ul>
    </section>
  {/if}
</div>

<style>
  .wrap { max-width: var(--content-width, 900px); margin: 0 auto; padding: 0 24px 80px; }
  .muted { color: var(--dim); }

  .block { margin-top: 36px; }
  .block-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; }
  .block-head h2 { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.06em; color: var(--dim); margin: 0; }
  .add-btn { display: inline-flex; align-items: center; gap: 6px; padding: 6px 10px; border: 1px solid var(--border); border-radius: 8px; background: transparent; color: var(--sec); font-size: 12.5px; font-weight: 600; cursor: pointer; }
  .add-btn:hover { background: var(--hover); color: var(--text); }
  .add-btn:focus-visible { outline: 2px solid var(--purple); outline-offset: 1px; }

  .colors { display: flex; flex-direction: column; gap: 18px; }
  .color { border: 1px solid var(--border); border-radius: 12px; padding: 14px; }
  .color-row { display: flex; align-items: flex-end; gap: 12px; flex-wrap: wrap; }
  .swatch-input { width: 40px; height: 40px; padding: 0; border: 1px solid var(--border); border-radius: 8px; background: none; cursor: pointer; flex-shrink: 0; }
  .swatch-input:focus-visible { outline: 2px solid var(--purple); outline-offset: 2px; }

  .fld { display: flex; flex-direction: column; gap: 5px; }
  .fld-label { font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; color: var(--dim); }
  .fld input { padding: 8px 10px; border: 1px solid var(--border); border-radius: 8px; background: var(--raised); color: var(--text); font-size: 13px; }
  .fld input:focus-visible { outline: 2px solid var(--purple); outline-offset: 1px; border-color: var(--purple); }
  .fld input[aria-invalid="true"] { border-color: var(--red, #d64545); }
  .name-fld { width: 150px; } .hex-fld { width: 110px; }
  .util { padding-bottom: 8px; }
  .icon-btn { margin-left: auto; display: inline-flex; padding: 8px; border: 1px solid var(--border); border-radius: 8px; background: transparent; color: var(--sec); cursor: pointer; }
  .icon-btn:hover:not(:disabled) { background: var(--hover); color: var(--text); }
  .icon-btn:disabled { opacity: 0.4; cursor: default; }
  .icon-btn:focus-visible { outline: 2px solid var(--purple); outline-offset: 1px; }

  .err { margin: 8px 0 0; font-size: 12px; color: var(--red, #d64545); }

  .scale { list-style: none; display: flex; margin: 14px 0 0; padding: 0; border-radius: 8px; overflow: hidden; border: 1px solid var(--border); }
  .shade { flex: 1; height: 44px; display: flex; align-items: flex-end; justify-content: center; }
  .shade-num { font-size: 9px; font-weight: 600; color: rgba(255, 255, 255, 0.9); text-shadow: 0 1px 2px rgba(0, 0, 0, 0.5); padding-bottom: 3px; }

  .scale-controls { display: flex; gap: 14px; flex-wrap: wrap; margin-bottom: 18px; }
  .scale-controls .fld input { width: 120px; }

  .type-preview { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 10px; }
  .type-preview li { display: flex; align-items: baseline; gap: 14px; }
  .type-tag, .space-tag { flex-shrink: 0; width: 34px; font-size: 11px; font-weight: 600; color: var(--dim); text-transform: uppercase; }
  .type-sample { color: var(--text); line-height: 1.2; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }

  .space-preview { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 10px; }
  .space-preview li { display: flex; align-items: center; gap: 14px; }
  .space-bar { height: 16px; border-radius: 4px; background: var(--purple); }
</style>
