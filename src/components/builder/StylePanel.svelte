<script>
  import { X } from 'lucide-svelte';

  let { editor } = $props();

  let node = $derived(editor.selectedNode);
  let override = $state(null);
  let newClass = $state('');

  let activeClass = $derived.by(() => {
    const list = node ? node.classes : [];
    if (override && list.includes(override)) return override;
    return list.length ? list[list.length - 1] : null;
  });

  let decls = $derived(activeClass ? (editor.classes[activeClass] || {}) : {});
  let display = $derived(decls.display || '');

  function val(prop) { return decls[prop] || ''; }
  function set(prop, e) { editor.setDeclaration(activeClass, prop, e.target.value); }

  function addClass() {
    const name = newClass.trim();
    if (!name) return;
    if (editor.addClassToNode(node.id, name)) override = name;
    newClass = '';
  }

  function onAddKey(e) {
    if (e.key === 'Enter') { e.preventDefault(); addClass(); }
  }
</script>

{#if node}
  <div class="styles">
    <div class="sec-head">Selector</div>

    {#if node.classes.length}
      <div class="chips" role="group" aria-label="Classes on this element">
        {#each node.classes as name (name)}
          <span class="chip" class:active={name === activeClass}>
            <button class="chip-name" onclick={() => (override = name)} aria-pressed={name === activeClass}>.{name}</button>
            <button class="chip-x" onclick={() => editor.removeClassFromNode(node.id, name)} aria-label={`Remove class ${name}`}>
              <X size={12} aria-hidden="true" />
            </button>
          </span>
        {/each}
      </div>
    {/if}

    <div class="add-row">
      <input
        type="text"
        placeholder="Add or create class"
        bind:value={newClass}
        onkeydown={onAddKey}
        aria-label="Add or create class"
      />
      <button class="add-btn" onclick={addClass} disabled={!newClass.trim()}>Add</button>
    </div>

    {#if activeClass}
      <div class="editing">Editing <span>.{activeClass}</span></div>

      {#snippet text(label, prop)}
        <label class="f">
          <span>{label}</span>
          <input type="text" value={val(prop)} oninput={(e) => set(prop, e)} />
        </label>
      {/snippet}

      {#snippet choose(label, prop, options)}
        <label class="f">
          <span>{label}</span>
          <select value={val(prop)} onchange={(e) => set(prop, e)}>
            <option value="">—</option>
            {#each options as o (o)}<option value={o}>{o}</option>{/each}
          </select>
        </label>
      {/snippet}

      <div class="sec-head">Layout</div>
      {@render choose('Display', 'display', ['block', 'flex', 'grid', 'inline-block', 'inline', 'none'])}
      {#if display === 'flex' || display === 'grid'}
        {@render choose('Direction', 'flex-direction', ['row', 'column'])}
        {@render text('Gap', 'gap')}
        {@render choose('Align items', 'align-items', ['stretch', 'flex-start', 'center', 'flex-end', 'baseline'])}
        {@render choose('Justify', 'justify-content', ['flex-start', 'center', 'flex-end', 'space-between', 'space-around'])}
      {/if}

      <div class="sec-head">Spacing</div>
      {@render text('Padding', 'padding')}
      {@render text('Margin', 'margin')}

      <div class="sec-head">Size</div>
      {@render text('Width', 'width')}
      {@render text('Height', 'height')}

      <div class="sec-head">Typography</div>
      {@render text('Text color', 'color')}
      {@render text('Font size', 'font-size')}
      {@render choose('Weight', 'font-weight', ['400', '500', '600', '700', '800'])}
      {@render choose('Align', 'text-align', ['left', 'center', 'right', 'justify'])}

      <div class="sec-head">Background</div>
      {@render text('Background', 'background-color')}

      <div class="sec-head">Border</div>
      {@render text('Radius', 'border-radius')}
      {@render text('Border', 'border')}
    {:else}
      <p class="hint">Add a class to start styling this element. Styles you set on a class apply everywhere that class is used.</p>
    {/if}
  </div>
{/if}

<style>
  .styles { margin-top: 4px; }

  .sec-head {
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--dim);
    margin: 18px 0 10px;
  }
  .sec-head:first-child { margin-top: 0; }

  .chips {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-bottom: 10px;
  }

  .chip {
    display: inline-flex;
    align-items: center;
    background: var(--hover);
    border-radius: 6px;
    overflow: hidden;
  }
  .chip.active { background: var(--sidebar-bg-active); }

  .chip-name {
    border: none;
    background: none;
    color: var(--sec);
    font-size: 12px;
    font-family: var(--font-mono, ui-monospace, monospace);
    padding: 5px 4px 5px 9px;
    cursor: pointer;
  }
  .chip.active .chip-name { color: var(--text); }
  .chip-name:focus-visible { outline: 2px solid var(--purple); outline-offset: -2px; }

  .chip-x {
    border: none;
    background: none;
    color: var(--dim);
    display: inline-flex;
    padding: 5px 7px 5px 3px;
    cursor: pointer;
  }
  .chip-x:hover { color: var(--red); }
  .chip-x:focus-visible { outline: 2px solid var(--purple); outline-offset: -2px; }

  .add-row { display: flex; gap: 6px; }

  .add-row input {
    flex: 1;
    min-width: 0;
    padding: 8px 10px;
    border: 1px solid transparent;
    border-radius: 7px;
    background: var(--hover);
    color: var(--text);
    font-size: 13px;
  }
  .add-row input:hover { border-color: var(--border); }
  .add-row input:focus-visible { outline: none; border-color: var(--purple); }

  .add-btn {
    padding: 8px 12px;
    border: none;
    border-radius: 7px;
    background: var(--hover);
    color: var(--text);
    font-size: 13px;
    font-weight: 500;
    cursor: pointer;
  }
  .add-btn:hover:not(:disabled) { background: var(--bg-active); }
  .add-btn:disabled { opacity: 0.4; cursor: default; }
  .add-btn:focus-visible { outline: 2px solid var(--purple); outline-offset: 1px; }

  .editing {
    font-size: 12px;
    color: var(--dim);
    margin: 14px 0 2px;
  }
  .editing span {
    color: var(--purple-soft);
    font-family: var(--font-mono, ui-monospace, monospace);
  }

  .f {
    display: grid;
    grid-template-columns: 92px 1fr;
    align-items: center;
    gap: 8px;
    margin-bottom: 8px;
  }
  .f span {
    font-size: 12px;
    color: var(--sec);
  }
  .f input,
  .f select {
    width: 100%;
    padding: 7px 9px;
    border: 1px solid transparent;
    border-radius: 6px;
    background: var(--hover);
    color: var(--text);
    font-size: 13px;
    font-family: inherit;
  }
  .f input:hover,
  .f select:hover { border-color: var(--border); }
  .f input:focus-visible,
  .f select:focus-visible { outline: none; border-color: var(--purple); }

  .hint {
    font-size: 12px;
    color: var(--dim);
    line-height: 1.5;
    margin: 4px 0 0;
  }
</style>
