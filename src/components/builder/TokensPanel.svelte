<script>
  import { colorScale, COLOR_STEPS, SCALE_STEPS, typeScale, spacingScale } from '$lib/builder-tokens.js';
  import { Plus, Trash2 } from 'lucide-svelte';

  let { editor } = $props();

  let newName = $state('');
  let newValue = $state('#3b82f6');

  let tokens = $derived(editor.tokens);
  let type = $derived(typeScale(tokens.type || {}));
  let spacing = $derived(spacingScale(tokens.spacing || {}));

  function addColor() {
    const name = newName.trim();
    if (!name) return;
    if (editor.addColorToken(name, newValue)) {
      newName = '';
      newValue = '#3b82f6';
    }
  }

  function onAddKey(e) {
    if (e.key === 'Enter') { e.preventDefault(); addColor(); }
  }
</script>

<div class="tokens">
  <div class="t-body">
    <div class="sec">Colors</div>
    <p class="note">One color generates a full shade scale plus <code>.text-</code>, <code>.bg-</code>, <code>.border-</code> classes.</p>

    {#each tokens.colors || [] as c (c.name)}
      <div class="color">
        <div class="color-head">
          <input
            class="swatch"
            type="color"
            value={c.value}
            oninput={(e) => editor.updateColorToken(c.name, { value: e.target.value })}
            aria-label={`${c.name} color value`}
          />
          <span class="cname">{c.name}</span>
          <label class="util">
            <input
              type="checkbox"
              checked={c.utilities}
              onchange={(e) => editor.updateColorToken(c.name, { utilities: e.target.checked })}
            />
            <span>classes</span>
          </label>
          <button class="del" onclick={() => editor.removeColorToken(c.name)} aria-label={`Remove ${c.name}`}>
            <Trash2 size={14} aria-hidden="true" />
          </button>
        </div>
        {#if colorScale(c.value)}
          {@const scale = colorScale(c.value)}
          <div class="ramp" aria-hidden="true">
            {#each COLOR_STEPS as step (step)}
              <span class="chip" style="background:{scale[step]}" title={`${c.name}-${step}`}></span>
            {/each}
          </div>
        {/if}
      </div>
    {/each}

    <div class="add">
      <input class="swatch" type="color" bind:value={newValue} aria-label="New color value" />
      <input
        class="add-name"
        type="text"
        placeholder="Color name"
        bind:value={newName}
        onkeydown={onAddKey}
        aria-label="New color name"
      />
      <button class="add-btn" onclick={addColor} disabled={!newName.trim()} aria-label="Add color">
        <Plus size={15} aria-hidden="true" />
      </button>
    </div>

    <div class="sec">Type scale</div>
    <div class="scale-fields">
      <label class="sf"><span>Min size</span><input type="number" value={tokens.type?.baseMin ?? 16} oninput={(e) => editor.setScaleOption('type', 'baseMin', e.target.value)} /></label>
      <label class="sf"><span>Max size</span><input type="number" value={tokens.type?.baseMax ?? 18} oninput={(e) => editor.setScaleOption('type', 'baseMax', e.target.value)} /></label>
      <label class="sf"><span>Ratio</span><input type="number" step="0.01" value={tokens.type?.ratio ?? 1.2} oninput={(e) => editor.setScaleOption('type', 'ratio', e.target.value)} /></label>
    </div>
    <div class="steps">
      {#each SCALE_STEPS as step (step)}
        <div class="step"><span class="step-name">--text-{step}</span><span class="step-prev" style="font-size:{type[step]}">Aa</span></div>
      {/each}
    </div>

    <div class="sec">Spacing scale</div>
    <div class="scale-fields">
      <label class="sf"><span>Min base</span><input type="number" value={tokens.spacing?.baseMin ?? 14} oninput={(e) => editor.setScaleOption('spacing', 'baseMin', e.target.value)} /></label>
      <label class="sf"><span>Max base</span><input type="number" value={tokens.spacing?.baseMax ?? 18} oninput={(e) => editor.setScaleOption('spacing', 'baseMax', e.target.value)} /></label>
      <label class="sf"><span>Ratio</span><input type="number" step="0.01" value={tokens.spacing?.ratio ?? 1.5} oninput={(e) => editor.setScaleOption('spacing', 'ratio', e.target.value)} /></label>
    </div>
    <div class="steps">
      {#each SCALE_STEPS as step (step)}
        <div class="step"><span class="step-name">--space-{step}</span><span class="bar" style="width:{spacing[step]}"></span></div>
      {/each}
    </div>
  </div>
</div>

<style>
  .tokens { flex: 1; min-height: 0; display: flex; flex-direction: column; overflow: hidden; }
  .t-body { overflow-y: auto; padding: 12px 14px 20px; flex: 1; }

  .sec {
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--dim);
    margin: 18px 0 8px;
  }
  .sec:first-child { margin-top: 0; }

  .note { font-size: 11px; color: var(--dim); line-height: 1.5; margin: 0 0 12px; }
  .note code { font-family: var(--font-mono, ui-monospace, monospace); color: var(--sec); }

  .color { margin-bottom: 12px; }
  .color-head { display: flex; align-items: center; gap: 8px; }
  .cname {
    flex: 1;
    font-size: 13px;
    color: var(--text);
    font-family: var(--font-mono, ui-monospace, monospace);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }
  .util { display: inline-flex; align-items: center; gap: 4px; font-size: 11px; color: var(--dim); cursor: pointer; }
  .util input { accent-color: var(--purple); }

  .swatch {
    width: 28px;
    height: 28px;
    padding: 0;
    border: 1px solid var(--border);
    border-radius: 6px;
    background: none;
    cursor: pointer;
    flex-shrink: 0;
  }

  .del { border: none; background: none; color: var(--dim); display: inline-flex; padding: 5px; border-radius: 6px; cursor: pointer; }
  .del:hover { color: var(--red); background: var(--hover); }
  .del:focus-visible { outline: 2px solid var(--purple); outline-offset: 1px; }

  .ramp { display: flex; gap: 0; margin-top: 6px; border-radius: 5px; overflow: hidden; }
  .ramp .chip { flex: 1; height: 18px; }

  .add { display: flex; align-items: center; gap: 8px; margin-top: 8px; }
  .add-name {
    flex: 1;
    min-width: 0;
    padding: 7px 10px;
    border: 1px solid transparent;
    border-radius: 7px;
    background: var(--hover);
    color: var(--text);
    font-size: 13px;
  }
  .add-name:hover { border-color: var(--border); }
  .add-name:focus-visible { outline: none; border-color: var(--purple); }
  .add-btn {
    display: inline-flex;
    padding: 7px;
    border: none;
    border-radius: 7px;
    background: var(--hover);
    color: var(--text);
    cursor: pointer;
  }
  .add-btn:hover:not(:disabled) { background: var(--bg-active); }
  .add-btn:disabled { opacity: 0.4; cursor: default; }
  .add-btn:focus-visible { outline: 2px solid var(--purple); outline-offset: 1px; }

  .scale-fields { display: flex; gap: 8px; margin-bottom: 12px; }
  .sf { flex: 1; display: flex; flex-direction: column; gap: 4px; }
  .sf span { font-size: 10px; text-transform: uppercase; letter-spacing: 0.04em; color: var(--dim); }
  .sf input {
    width: 100%;
    padding: 6px 8px;
    border: 1px solid transparent;
    border-radius: 6px;
    background: var(--hover);
    color: var(--text);
    font-size: 13px;
  }
  .sf input:hover { border-color: var(--border); }
  .sf input:focus-visible { outline: none; border-color: var(--purple); }

  .steps { display: flex; flex-direction: column; gap: 6px; }
  .step { display: flex; align-items: center; gap: 10px; min-height: 22px; }
  .step-name {
    font-size: 11px;
    color: var(--sec);
    font-family: var(--font-mono, ui-monospace, monospace);
    width: 92px;
    flex-shrink: 0;
  }
  .step-prev { color: var(--text); line-height: 1; }
  .bar { height: 10px; background: var(--purple); border-radius: 3px; }
</style>
