<script>
  import { X } from 'lucide-svelte';

  let { editor } = $props();

  let node = $derived(editor.selectedNode);
  let override = $state(null);
  let query = $state('');
  let open = $state(false);
  let highlight = $state(0);
  let comboEl = $state(null);
  let view = $state('visual');
  let cssText = $state('');
  let cssFocused = $state(false);

  let activeClass = $derived.by(() => {
    const list = node ? node.classes : [];
    if (override && list.includes(override)) return override;
    return list.length ? list[list.length - 1] : null;
  });

  let styleTarget = $derived(activeClass ? { kind: 'class', name: activeClass } : (node ? { kind: 'element', id: node.id } : null));
  let targetLabel = $derived(activeClass ? `.${activeClass}` : (node ? `this ${node.tag}` : ''));

  let display = $derived(val('display'));
  let position = $derived(val('position'));
  let bpLabel = $derived(editor.breakpoint === 'tablet' ? 'Tablet' : editor.breakpoint === 'mobile' ? 'Mobile' : null);

  let suggestions = $derived.by(() => {
    const q = query.trim().toLowerCase();
    const onNode = new Set(node ? node.classes : []);
    const usage = editor.classUsage;
    const matches = editor.classNames
      .filter((name) => !onNode.has(name) && (!q || name.toLowerCase().includes(q)))
      .sort((a, b) => (usage[b] || 0) - (usage[a] || 0) || a.localeCompare(b))
      .map((name) => ({ name, count: usage[name] || 0, exists: true }));
    const exact = query.trim();
    const canCreate = exact && !editor.classNames.includes(exact) && !onNode.has(exact);
    return canCreate ? [...matches, { name: exact, exists: false }] : matches;
  });

  $effect(() => {
    const t = styleTarget;
    const serialized = !t ? '' : (t.kind === 'class' ? editor.classCssText(t.name) : editor.elementCssText(t.id));
    if (!cssFocused) cssText = serialized;
  });

  function val(prop) {
    const t = styleTarget;
    if (!t) return '';
    return (t.kind === 'class' ? editor.getDeclaration(t.name, prop) : editor.getElementDeclaration(t.id, prop)) || '';
  }
  function set(prop, e) {
    const t = styleTarget;
    if (!t) return;
    if (t.kind === 'class') editor.setDeclaration(t.name, prop, e.target.value);
    else editor.setElementDeclaration(t.id, prop, e.target.value);
  }

  function onCssInput(e) {
    cssText = e.target.value;
    const t = styleTarget;
    if (!t) return;
    if (t.kind === 'class') editor.setClassCss(t.name, cssText);
    else editor.setElementCss(t.id, cssText);
  }

  function choose(item) {
    if (!item) return;
    if (editor.addClassToNode(node.id, item.name)) override = item.name;
    query = '';
    open = false;
    highlight = 0;
  }

  function onComboKey(e) {
    if (e.key === 'ArrowDown') { e.preventDefault(); open = true; highlight = Math.min(highlight + 1, suggestions.length - 1); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); highlight = Math.max(highlight - 1, 0); }
    else if (e.key === 'Enter') { e.preventDefault(); choose(suggestions[highlight]); }
    else if (e.key === 'Escape') { open = false; }
  }

  $effect(() => {
    query;
    highlight = 0;
  });

  $effect(() => {
    if (!open) return;
    function onDoc(e) {
      if (comboEl && !comboEl.contains(e.target)) open = false;
    }
    document.addEventListener('click', onDoc);
    return () => document.removeEventListener('click', onDoc);
  });
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

    <div class="combo" bind:this={comboEl}>
      <input
        type="text"
        role="combobox"
        placeholder="Search or create class"
        bind:value={query}
        onfocus={() => (open = true)}
        oninput={() => (open = true)}
        onkeydown={onComboKey}
        aria-expanded={open}
        aria-controls="class-listbox"
        aria-autocomplete="list"
        aria-label="Search or create class"
      />
      {#if open && suggestions.length}
        <ul class="menu" id="class-listbox" role="listbox">
          {#each suggestions as item, i (item.name + (item.exists ? '' : '-new'))}
            <li role="none">
              <button
                role="option"
                aria-selected={i === highlight}
                class="opt"
                class:hl={i === highlight}
                onmousemove={() => (highlight = i)}
                onclick={() => choose(item)}
              >
                {#if item.exists}
                  <span class="opt-name">.{item.name}</span>
                  <span class="opt-meta">{item.count === 0 ? 'Unused' : `${item.count}×`}</span>
                {:else}
                  <span class="opt-name">Create <strong>.{item.name}</strong></span>
                {/if}
              </button>
            </li>
          {/each}
        </ul>
      {/if}
    </div>

    {#if styleTarget}
      <div class="editing-row">
        <span class="editing">Editing <span class="ec">{targetLabel}</span>{#if bpLabel}<span class="bp">@ {bpLabel}</span>{/if}</span>
        <div class="vtabs" role="tablist" aria-label="Style editor view">
          <button role="tab" aria-selected={view === 'visual'} class:on={view === 'visual'} onclick={() => (view = 'visual')}>Visual</button>
          <button role="tab" aria-selected={view === 'css'} class:on={view === 'css'} onclick={() => (view = 'css')}>CSS</button>
        </div>
      </div>

      {#if view === 'css'}
        <label class="css-label" for="css-box">CSS for {targetLabel}</label>
        <textarea
          id="css-box"
          class="css-box"
          spellcheck="false"
          autocomplete="off"
          autocapitalize="off"
          value={cssText}
          oninput={onCssInput}
          onfocus={() => (cssFocused = true)}
          onblur={() => (cssFocused = false)}
          rows="14"
          placeholder={"color: var(--primary);\n\n&:hover {\n  opacity: 0.8;\n}"}
        ></textarea>
        <p class="css-hint">Plain CSS — nesting (<code>&amp;:hover</code>, <code>&amp; .child</code>) and <code>@media</code> supported. Edits sync with the fields.</p>
      {:else}
        {#snippet text(label, prop, placeholder = '')}
          <label class="f">
            <span>{label}</span>
            <input type="text" value={val(prop)} oninput={(e) => set(prop, e)} placeholder={placeholder} />
          </label>
        {/snippet}

        {#snippet pick(label, prop, options)}
          <label class="f">
            <span>{label}</span>
            <select value={val(prop)} onchange={(e) => set(prop, e)}>
              <option value="">—</option>
              {#each options as o (o)}<option value={o}>{o}</option>{/each}
            </select>
          </label>
        {/snippet}

        <div class="sec-head">Layout</div>
        {@render pick('Display', 'display', ['block', 'flex', 'grid', 'inline-block', 'inline', 'none'])}
        {#if display === 'flex' || display === 'grid'}
          {@render pick('Direction', 'flex-direction', ['row', 'column', 'row-reverse', 'column-reverse'])}
          {@render pick('Wrap', 'flex-wrap', ['nowrap', 'wrap', 'wrap-reverse'])}
          {@render text('Gap', 'gap')}
          {@render pick('Align items', 'align-items', ['stretch', 'flex-start', 'center', 'flex-end', 'baseline'])}
          {@render pick('Justify', 'justify-content', ['flex-start', 'center', 'flex-end', 'space-between', 'space-around', 'space-evenly'])}
        {/if}
        {@render pick('Overflow', 'overflow', ['visible', 'hidden', 'auto', 'scroll', 'clip'])}

        <div class="sec-head">Position</div>
        {@render pick('Position', 'position', ['static', 'relative', 'absolute', 'fixed', 'sticky'])}
        {#if position && position !== 'static'}
          {@render text('Top', 'top')}
          {@render text('Right', 'right')}
          {@render text('Bottom', 'bottom')}
          {@render text('Left', 'left')}
          {@render text('Z-index', 'z-index')}
        {/if}

        <div class="sec-head">Spacing</div>
        {@render text('Padding', 'padding')}
        {@render text('Margin', 'margin')}

        <div class="sec-head">Size</div>
        {@render text('Width', 'width')}
        {@render text('Height', 'height')}
        {@render text('Min W', 'min-width')}
        {@render text('Max W', 'max-width')}
        {@render text('Min H', 'min-height')}
        {@render text('Max H', 'max-height')}

        <div class="sec-head">Typography</div>
        {@render text('Color', 'color')}
        {@render text('Font size', 'font-size')}
        {@render pick('Weight', 'font-weight', ['300', '400', '500', '600', '700', '800', '900'])}
        {@render text('Line height', 'line-height')}
        {@render text('Letter sp.', 'letter-spacing')}
        {@render pick('Align', 'text-align', ['left', 'center', 'right', 'justify'])}
        {@render pick('Transform', 'text-transform', ['none', 'uppercase', 'lowercase', 'capitalize'])}

        <div class="sec-head">Background</div>
        {@render text('Color', 'background-color')}
        {@render text('Image', 'background-image', 'linear-gradient(…) / url(…)')}
        {@render text('Size', 'background-size')}
        {@render text('Position', 'background-position')}
        {@render pick('Repeat', 'background-repeat', ['no-repeat', 'repeat', 'repeat-x', 'repeat-y'])}

        <div class="sec-head">Border</div>
        {@render text('Border', 'border')}
        {@render text('Radius', 'border-radius')}
        {@render text('Shadow', 'box-shadow')}

        <div class="sec-head">Effects</div>
        {@render text('Opacity', 'opacity')}
        {@render text('Filter', 'filter', 'blur(80px)')}
        {@render text('Transform', 'transform', 'scale(1.02)')}
        {@render text('Transition', 'transition')}
        {@render pick('Blend', 'mix-blend-mode', ['normal', 'multiply', 'screen', 'overlay', 'difference', 'lighten', 'darken'])}
      {/if}
    {/if}

    {#if styleTarget && styleTarget.kind === 'element'}
      <p class="hint">Styling this element directly. Add a class above to make these styles reusable across elements.</p>
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

  .combo { position: relative; }

  .combo > input {
    width: 100%;
    padding: 8px 10px;
    border: 1px solid transparent;
    border-radius: 7px;
    background: var(--hover);
    color: var(--text);
    font-size: 13px;
  }
  .combo > input:hover { border-color: var(--border); }
  .combo > input:focus-visible { outline: none; border-color: var(--purple); }

  .menu {
    position: absolute;
    top: calc(100% + 4px);
    left: 0;
    right: 0;
    z-index: 20;
    list-style: none;
    margin: 0;
    padding: 4px;
    max-height: 240px;
    overflow-y: auto;
    background: var(--raised);
    border: 1px solid var(--border);
    border-radius: 8px;
    box-shadow: var(--shadow-lg);
  }

  .opt {
    display: flex;
    align-items: center;
    gap: 8px;
    width: 100%;
    padding: 7px 9px;
    border: none;
    border-radius: 6px;
    background: none;
    color: var(--sec);
    font-size: 13px;
    text-align: left;
    cursor: pointer;
  }
  .opt.hl { background: var(--hover); color: var(--text); }

  .opt-name {
    flex: 1;
    font-family: var(--font-mono, ui-monospace, monospace);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }
  .opt-name strong { color: var(--purple-soft); font-weight: 600; }
  .opt-meta { font-size: 11px; color: var(--dim); flex-shrink: 0; }

  .editing-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    margin: 14px 0 8px;
  }
  .editing {
    font-size: 12px;
    color: var(--dim);
  }
  .editing .ec {
    color: var(--purple-soft);
    font-family: var(--font-mono, ui-monospace, monospace);
  }
  .editing .bp {
    margin-left: 6px;
    padding: 1px 6px;
    border-radius: 5px;
    background: var(--purple-bg, var(--hover));
    color: var(--purple-soft, var(--purple));
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
  }

  .vtabs {
    display: inline-flex;
    gap: 2px;
    background: var(--hover);
    border-radius: 7px;
    padding: 2px;
    flex-shrink: 0;
  }
  .vtabs button {
    padding: 4px 10px;
    border: none;
    border-radius: 5px;
    background: transparent;
    color: var(--sec);
    font-size: 11px;
    font-weight: 600;
    cursor: pointer;
  }
  .vtabs button.on { background: var(--raised); color: var(--text); }
  .vtabs button:focus-visible { outline: 2px solid var(--purple); outline-offset: 1px; }

  .css-label {
    display: block;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--dim);
    margin-bottom: 6px;
  }
  .css-box {
    width: 100%;
    padding: 10px 11px;
    border: 1px solid var(--border);
    border-radius: 8px;
    background: var(--bg);
    color: var(--text);
    font-family: var(--font-mono, ui-monospace, monospace);
    font-size: 12.5px;
    line-height: 1.55;
    resize: vertical;
    tab-size: 2;
  }
  .css-box:focus-visible { outline: none; border-color: var(--purple); }
  .css-hint {
    font-size: 11px;
    color: var(--dim);
    line-height: 1.5;
    margin: 8px 0 0;
  }
  .css-hint code {
    font-family: var(--font-mono, ui-monospace, monospace);
    background: var(--hover);
    padding: 1px 4px;
    border-radius: 4px;
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
