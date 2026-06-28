<script>
  import { X } from 'lucide-svelte';

  let { editor } = $props();

  let node = $derived(editor.selectedNode);
  let override = $state(null);
  let query = $state('');
  let open = $state(false);
  let highlight = $state(0);
  let comboEl = $state(null);

  let activeClass = $derived.by(() => {
    const list = node ? node.classes : [];
    if (override && list.includes(override)) return override;
    return list.length ? list[list.length - 1] : null;
  });

  let decls = $derived(activeClass ? (editor.classes[activeClass] || {}) : {});
  let display = $derived(decls.display || '');

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

  function val(prop) { return decls[prop] || ''; }
  function set(prop, e) { editor.setDeclaration(activeClass, prop, e.target.value); }

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
