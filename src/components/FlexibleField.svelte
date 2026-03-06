<script>
  import MediaPicker from '$components/MediaPicker.svelte';

  let {
    layouts = '{}',
    items = '[]',
    onchange = () => {},
  } = $props();

  let rows = $state([]);
  let layoutDefs = $state({});
  let collapsed = $state({});
  let showAddMenu = $state(false);
  let pickerRow = $state(null);
  let showPicker = $state(false);

  $effect(() => {
    try {
      layoutDefs = typeof layouts === 'string' ? JSON.parse(layouts) : layouts;
    } catch {
      layoutDefs = {};
    }
  });

  $effect(() => {
    try {
      const parsed = typeof items === 'string' ? JSON.parse(items) : items;
      rows = Array.isArray(parsed) ? parsed : [];
    } catch {
      rows = [];
    }
  });

  let layoutKeys = $derived(Object.keys(layoutDefs));

  function getLayoutLabel(key) {
    return layoutDefs[key]?.label || key;
  }

  function getLayoutFields(key) {
    return layoutDefs[key]?.fields || {};
  }

  function addRow(layoutKey) {
    const fields = getLayoutFields(layoutKey);
    const newRow = { _layout: layoutKey };
    for (const key of Object.keys(fields)) {
      newRow[key] = '';
    }
    rows = [...rows, newRow];
    showAddMenu = false;
    onchange(rows);
  }

  function removeRow(index) {
    const id = rowId(index);
    const next = { ...collapsed };
    delete next[id];
    collapsed = next;
    rows = rows.filter((_, i) => i !== index);
    onchange(rows);
  }

  function updateField(index, key, value) {
    rows = rows.map((row, i) =>
      i === index ? { ...row, [key]: value } : row
    );
    onchange(rows);
  }

  function moveUp(index) {
    if (index <= 0) return;
    const newRows = [...rows];
    [newRows[index - 1], newRows[index]] = [newRows[index], newRows[index - 1]];
    rows = newRows;
    onchange(rows);
  }

  function moveDown(index) {
    if (index >= rows.length - 1) return;
    const newRows = [...rows];
    [newRows[index], newRows[index + 1]] = [newRows[index + 1], newRows[index]];
    rows = newRows;
    onchange(rows);
  }

  function toggleCollapse(index) {
    const id = rowId(index);
    collapsed = { ...collapsed, [id]: !collapsed[id] };
  }

  function rowId(index) {
    return `row-${index}`;
  }

  function isCollapsed(index) {
    return !!collapsed[rowId(index)];
  }

  function getRowSummary(row) {
    const fields = getLayoutFields(row._layout);
    const keys = Object.keys(fields);
    for (const key of keys) {
      if (fields[key].type === 'text' && row[key]) {
        return row[key].length > 50 ? row[key].substring(0, 50) + '...' : row[key];
      }
    }
    return '';
  }

  function openImagePicker(index, key) {
    pickerRow = { index, key };
    showPicker = true;
  }

  function handleImageSelect(file) {
    if (pickerRow) {
      updateField(pickerRow.index, pickerRow.key, file.path);
    }
    showPicker = false;
    pickerRow = null;
  }

  function handleToggle(index, key, currentValue) {
    const newVal = currentValue === '1' || currentValue === 'true' ? '0' : '1';
    updateField(index, key, newVal);
  }

  function getSelectOptions(fieldDef) {
    if (!fieldDef.options) return [];
    if (Array.isArray(fieldDef.options)) return fieldDef.options;
    try {
      return JSON.parse(fieldDef.options);
    } catch {
      return [];
    }
  }

  function handleClickOutsideMenu(e) {
    if (showAddMenu && !e.target.closest('.fx-add-wrap')) {
      showAddMenu = false;
    }
  }
</script>

<svelte:document onclick={handleClickOutsideMenu} />

<div class="fx">
  {#if rows.length === 0}
    <div class="fx-empty">
      <p class="fx-empty-text">No content blocks added yet</p>
    </div>
  {/if}

  {#each rows as row, i (i)}
    {@const layoutKey = row._layout}
    {@const label = getLayoutLabel(layoutKey)}
    {@const fields = getLayoutFields(layoutKey)}
    {@const summary = getRowSummary(row)}
    {@const rowCollapsed = isCollapsed(i)}

    <div class="fx-row" class:fx-row-collapsed={rowCollapsed}>
      <div class="fx-row-header" onclick={() => toggleCollapse(i)}>
        <div class="fx-row-header-left">
          <span class="fx-chevron" class:fx-chevron-collapsed={rowCollapsed}>
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
          </span>
          <span class="fx-layout-badge">{label}</span>
          {#if rowCollapsed && summary}
            <span class="fx-row-summary">{summary}</span>
          {/if}
        </div>
        <div class="fx-row-actions" onclick={(e) => e.stopPropagation()}>
          <button
            class="fx-action-btn"
            onclick={() => moveUp(i)}
            disabled={i === 0}
            title="Move up"
          >
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="18 15 12 9 6 15"/></svg>
          </button>
          <button
            class="fx-action-btn"
            onclick={() => moveDown(i)}
            disabled={i === rows.length - 1}
            title="Move down"
          >
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
          </button>
          <button
            class="fx-action-btn fx-remove-btn"
            onclick={() => removeRow(i)}
            title="Remove block"
          >
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
          </button>
        </div>
      </div>

      {#if !rowCollapsed}
        <div class="fx-row-body">
          {#each Object.entries(fields) as [key, fieldDef]}
            <div class="fx-field">
              <label class="fx-label">{fieldDef.label || key}</label>

              {#if fieldDef.type === 'text'}
                <input
                  class="fx-input"
                  type="text"
                  value={row[key] || ''}
                  oninput={(e) => updateField(i, key, e.target.value)}
                  placeholder={fieldDef.placeholder || ''}
                />

              {:else if fieldDef.type === 'textarea'}
                <textarea
                  class="fx-textarea"
                  rows="3"
                  value={row[key] || ''}
                  oninput={(e) => updateField(i, key, e.target.value)}
                  placeholder={fieldDef.placeholder || ''}
                ></textarea>

              {:else if fieldDef.type === 'richtext'}
                <textarea
                  class="fx-textarea fx-textarea-rich"
                  rows="5"
                  value={row[key] || ''}
                  oninput={(e) => updateField(i, key, e.target.value)}
                  placeholder={fieldDef.placeholder || 'Enter HTML content...'}
                ></textarea>

              {:else if fieldDef.type === 'image'}
                <div class="fx-image-field">
                  {#if row[key]}
                    <div class="fx-image-preview">
                      <img class="fx-thumb" src={row[key]} alt="" />
                      <button class="fx-image-clear" onclick={() => updateField(i, key, '')} type="button" aria-label="Remove image">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                      </button>
                    </div>
                  {:else}
                    <button class="fx-image-upload" onclick={() => openImagePicker(i, key)} type="button">
                      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                      Choose
                    </button>
                  {/if}
                </div>

              {:else if fieldDef.type === 'number'}
                <input
                  class="fx-input"
                  type="number"
                  value={row[key] || ''}
                  oninput={(e) => updateField(i, key, e.target.value)}
                  placeholder={fieldDef.placeholder || '0'}
                />

              {:else if fieldDef.type === 'link'}
                <input
                  class="fx-input"
                  type="url"
                  value={row[key] || ''}
                  oninput={(e) => updateField(i, key, e.target.value)}
                  placeholder={fieldDef.placeholder || 'https://'}
                />

              {:else if fieldDef.type === 'color'}
                <div class="fx-color-row">
                  <input
                    type="color"
                    value={row[key] || '#000000'}
                    oninput={(e) => updateField(i, key, e.target.value)}
                    class="fx-color-swatch"
                  />
                  <input
                    class="fx-input"
                    type="text"
                    value={row[key] || ''}
                    oninput={(e) => updateField(i, key, e.target.value)}
                    placeholder="#000000"
                    style="flex: 1;"
                  />
                </div>

              {:else if fieldDef.type === 'toggle'}
                {@const isOn = row[key] === '1' || row[key] === 'true'}
                <div class="fx-toggle-row">
                  <button
                    class="toggle"
                    class:active={isOn}
                    onclick={() => handleToggle(i, key, row[key])}
                    type="button"
                    role="switch"
                    aria-checked={isOn}
                  ></button>
                  <span class="fx-toggle-label">{isOn ? 'On' : 'Off'}</span>
                </div>

              {:else if fieldDef.type === 'select'}
                {@const opts = getSelectOptions(fieldDef)}
                <select
                  class="fx-select"
                  value={row[key] || ''}
                  onchange={(e) => updateField(i, key, e.target.value)}
                >
                  <option value="">Select...</option>
                  {#each opts as opt}
                    <option value={opt}>{opt}</option>
                  {/each}
                </select>

              {:else if fieldDef.type === 'date'}
                <input
                  class="fx-input"
                  type="date"
                  value={row[key] || ''}
                  oninput={(e) => updateField(i, key, e.target.value)}
                />

              {:else}
                <input
                  class="fx-input"
                  type="text"
                  value={row[key] || ''}
                  oninput={(e) => updateField(i, key, e.target.value)}
                />
              {/if}
            </div>
          {/each}
        </div>
      {/if}
    </div>
  {/each}

  <div class="fx-add-wrap">
    <button class="btn btn-secondary btn-sm" onclick={() => showAddMenu = !showAddMenu}>
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
      Add Layout
    </button>
    {#if showAddMenu && layoutKeys.length > 0}
      <div class="fx-add-menu">
        {#each layoutKeys as key}
          <button class="fx-add-menu-item" onclick={() => addRow(key)}>
            {getLayoutLabel(key)}
          </button>
        {/each}
      </div>
    {/if}
  </div>
</div>

{#if showPicker}
  <MediaPicker
    onselect={handleImageSelect}
    onclose={() => { showPicker = false; pickerRow = null; }}
  />
{/if}

<style>
  .fx {
    display: flex;
    flex-direction: column;
    gap: 0;
  }

  /* Empty state */
  .fx-empty {
    padding: 24px 0 8px;
  }

  .fx-empty-text {
    font-size: 13px;
    color: var(--text-tertiary);
    margin: 0;
  }

  /* Layout row */
  .fx-row {
    border: 1px solid var(--border-primary);
    border-radius: 8px;
    margin-bottom: 8px;
    overflow: hidden;
    transition: border-color 0.15s;
  }

  .fx-row:hover {
    border-color: var(--border-secondary);
  }

  /* Row header */
  .fx-row-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 8px 12px;
    background: var(--bg-secondary);
    cursor: pointer;
    user-select: none;
    transition: background 0.1s;
    min-height: 36px;
  }

  .fx-row-header:hover {
    background: var(--bg-tertiary);
  }

  .fx-row-header-left {
    display: flex;
    align-items: center;
    gap: 8px;
    min-width: 0;
    flex: 1;
  }

  .fx-chevron {
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--text-tertiary);
    transition: transform 0.15s ease;
    flex-shrink: 0;
  }

  .fx-chevron-collapsed {
    transform: rotate(-90deg);
  }

  .fx-layout-badge {
    font-size: 11px;
    font-weight: 600;
    color: var(--text-secondary);
    text-transform: uppercase;
    letter-spacing: 0.04em;
    flex-shrink: 0;
  }

  .fx-row-summary {
    font-size: 12px;
    color: var(--text-tertiary);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    min-width: 0;
  }

  /* Row action buttons */
  .fx-row-actions {
    display: flex;
    align-items: center;
    gap: 2px;
    flex-shrink: 0;
    margin-left: 8px;
  }

  .fx-action-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 26px;
    height: 26px;
    background: none;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    color: var(--text-tertiary);
    transition: background 0.1s, color 0.1s;
  }

  .fx-action-btn:hover {
    background: var(--bg-primary);
    color: var(--text-primary);
  }

  .fx-action-btn:disabled {
    opacity: 0.3;
    cursor: default;
  }

  .fx-action-btn:disabled:hover {
    background: none;
    color: var(--text-tertiary);
  }

  .fx-remove-btn:hover {
    color: var(--danger);
  }

  /* Row body / fields */
  .fx-row-body {
    padding: 4px 16px 16px;
  }

  .fx-field {
    padding: 14px 0;
    border-bottom: 1px solid var(--border-secondary);
  }

  .fx-field:last-child {
    border-bottom: none;
    padding-bottom: 0;
  }

  .fx-label {
    display: block;
    font-size: 11px;
    font-weight: 600;
    color: var(--text-tertiary);
    text-transform: uppercase;
    letter-spacing: 0.06em;
    margin-bottom: 8px;
  }

  /* Text inputs */
  .fx-input {
    width: 100%;
    padding: 10px 14px;
    border: 1px solid transparent;
    border-radius: 8px;
    background: var(--bg-tertiary);
    font-size: 15px;
    color: var(--text-primary);
    font-family: var(--font-sans);
    outline: none;
    transition: border-color 0.15s, box-shadow 0.15s;
  }

  .fx-input:hover {
    border-color: var(--border-secondary);
  }

  .fx-input:focus {
    border-color: var(--accent);
    box-shadow: 0 0 0 3px var(--accent-soft);
  }

  .fx-input::placeholder {
    color: var(--text-light);
  }

  /* Textarea */
  .fx-textarea {
    width: 100%;
    padding: 10px 14px;
    border: 1px solid transparent;
    border-radius: 8px;
    background: var(--bg-tertiary);
    font-size: 15px;
    color: var(--text-primary);
    font-family: var(--font-sans);
    outline: none;
    resize: vertical;
    min-height: 60px;
    transition: border-color 0.15s, box-shadow 0.15s;
  }

  .fx-textarea:hover {
    border-color: var(--border-secondary);
  }

  .fx-textarea:focus {
    border-color: var(--accent);
    box-shadow: 0 0 0 3px var(--accent-soft);
  }

  .fx-textarea::placeholder {
    color: var(--text-light);
  }

  .fx-textarea-rich {
    font-family: var(--font-mono, monospace);
    font-size: 13px;
  }

  /* Select */
  .fx-select {
    width: 100%;
    padding: 10px 14px;
    border: 1px solid transparent;
    border-radius: 8px;
    background: var(--bg-tertiary);
    font-size: 15px;
    color: var(--text-primary);
    font-family: var(--font-sans);
    outline: none;
    cursor: pointer;
    appearance: none;
    transition: border-color 0.15s, box-shadow 0.15s;
  }

  .fx-select:focus {
    border-color: var(--accent);
    box-shadow: 0 0 0 3px var(--accent-soft);
  }

  /* Image field */
  .fx-image-field {
    margin-top: 4px;
  }

  .fx-image-preview {
    position: relative;
    display: inline-block;
  }

  .fx-image-preview .fx-thumb {
    max-width: 200px;
    max-height: 120px;
    border-radius: var(--radius-md);
    display: block;
  }

  .fx-image-clear {
    position: absolute;
    top: 4px;
    right: 4px;
    width: 24px;
    height: 24px;
    border-radius: 50%;
    background: rgba(0, 0, 0, 0.5);
    color: white;
    border: none;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    opacity: 0;
    transition: opacity 0.15s;
  }

  .fx-image-preview:hover .fx-image-clear {
    opacity: 1;
  }

  .fx-image-upload {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 14px;
    border: 1px dashed var(--border-secondary);
    border-radius: var(--radius-md);
    background: none;
    color: var(--text-tertiary);
    font-size: 13px;
    font-weight: 500;
    cursor: pointer;
    transition: border-color 0.15s, color 0.15s;
  }

  .fx-image-upload:hover {
    border-color: var(--accent);
    color: var(--accent);
  }

  /* Color row */
  .fx-color-row {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
  }

  .fx-color-swatch {
    width: 36px;
    height: 36px;
    border: 1px solid var(--border-secondary);
    border-radius: var(--radius-sm);
    padding: 2px;
    cursor: pointer;
    background: none;
    flex-shrink: 0;
  }

  /* Toggle row */
  .fx-toggle-row {
    display: flex;
    align-items: center;
    gap: 8px;
  }

  .fx-toggle-label {
    font-size: 13px;
    color: var(--text-tertiary);
  }

  /* Add layout button + dropdown */
  .fx-add-wrap {
    position: relative;
    display: inline-block;
    margin-top: 8px;
  }

  .fx-add-menu {
    position: absolute;
    top: 100%;
    left: 0;
    margin-top: 4px;
    min-width: 180px;
    background: var(--bg-primary);
    border: 1px solid var(--border-primary);
    border-radius: 8px;
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1);
    z-index: 20;
    overflow: hidden;
    padding: 4px;
  }

  .fx-add-menu-item {
    display: block;
    width: 100%;
    padding: 8px 12px;
    text-align: left;
    background: none;
    border: none;
    border-radius: 4px;
    font-size: 13px;
    font-weight: 500;
    color: var(--text-primary);
    cursor: pointer;
    transition: background 0.1s;
  }

  .fx-add-menu-item:hover {
    background: var(--bg-secondary);
  }
</style>
