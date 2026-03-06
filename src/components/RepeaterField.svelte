<script>
  import MediaPicker from '$components/MediaPicker.svelte';

  let {
    schema = '{}',
    items = '[]',
    onchange = () => {},
  } = $props();

  let rows = $state([]);
  let schemaObj = $state({});
  let pickerRow = $state(null);  // { index, key }
  let showPicker = $state(false);

  $effect(() => {
    try {
      schemaObj = typeof schema === 'string' ? JSON.parse(schema) : schema;
    } catch {
      schemaObj = {};
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

  function addRow() {
    const newRow = {};
    for (const key of Object.keys(schemaObj)) {
      newRow[key] = '';
    }
    rows = [...rows, newRow];
    onchange(rows);
  }

  function removeRow(index) {
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
</script>

<div>
  {#each rows as row, i (i)}
    <div class="repeater-row">
      <div class="repeater-handle">
        <button class="btn btn-ghost btn-sm" onclick={() => moveUp(i)} disabled={i === 0} title="Move up">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="18 15 12 9 6 15"/></svg>
        </button>
        <button class="btn btn-ghost btn-sm" onclick={() => moveDown(i)} disabled={i === rows.length - 1} title="Move down">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
        </button>
      </div>
      <div class="repeater-fields">
        {#each Object.entries(schemaObj) as [key, type]}
          <div class="form-group">
            <label class="form-label rp-label">{key}</label>
            {#if type === 'image'}
              <div class="rp-image-field">
                {#if row[key]}
                  <img class="rp-thumb" src={row[key]} alt="" />
                {/if}
                <button class="rp-pick-btn" onclick={() => openImagePicker(i, key)}>
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                  {row[key] ? 'Change' : 'Pick image'}
                </button>
                {#if row[key]}
                  <button class="rp-clear-btn" onclick={() => updateField(i, key, '')}>Remove</button>
                {/if}
              </div>
            {:else if type === 'textarea'}
              <textarea
                class="input"
                rows="2"
                value={row[key] || ''}
                oninput={(e) => updateField(i, key, e.target.value)}
              ></textarea>
            {:else}
              <input
                class="input"
                type={type === 'number' ? 'number' : type === 'date' ? 'date' : type === 'color' ? 'color' : 'text'}
                value={row[key] || ''}
                oninput={(e) => updateField(i, key, e.target.value)}
              />
            {/if}
          </div>
        {/each}
      </div>
      <button class="btn btn-danger btn-sm" onclick={() => removeRow(i)} title="Remove">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>
  {/each}

  <button class="btn btn-secondary btn-sm" onclick={addRow} style="margin-top: var(--space-sm);">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
    Add Photo
  </button>
</div>

{#if showPicker}
  <MediaPicker
    onselect={handleImageSelect}
    onclose={() => { showPicker = false; pickerRow = null; }}
  />
{/if}

<style>
  .repeater-row {
    display: flex;
    align-items: flex-start;
    gap: var(--space-sm);
    padding: var(--space-md) 0;
    border-bottom: 1px solid var(--border-primary);
  }

  .repeater-handle {
    display: flex;
    flex-direction: column;
    gap: 2px;
    flex-shrink: 0;
    padding-top: 2px;
  }

  .repeater-fields {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: var(--space-sm);
  }

  .rp-label {
    font-size: 11px;
    font-weight: 500;
    color: var(--text-tertiary);
    text-transform: uppercase;
    letter-spacing: 0.04em;
    margin-bottom: 4px;
  }

  .rp-image-field {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
  }

  .rp-thumb {
    width: 48px;
    height: 48px;
    object-fit: cover;
    border-radius: var(--radius-sm);
    border: 1px solid var(--border-primary);
    flex-shrink: 0;
  }

  .rp-pick-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 5px 10px;
    font-size: 12px;
    font-weight: 500;
    color: var(--text-secondary);
    background: var(--bg-secondary);
    border: 1px solid var(--border-primary);
    border-radius: var(--radius-sm);
    cursor: pointer;
    transition: color 0.15s, border-color 0.15s;
  }

  .rp-pick-btn:hover {
    color: var(--text-primary);
    border-color: var(--border-secondary);
  }

  .rp-clear-btn {
    font-size: 12px;
    color: var(--text-tertiary);
    background: none;
    border: none;
    cursor: pointer;
    padding: 0;
    transition: color 0.15s;
  }

  .rp-clear-btn:hover {
    color: var(--danger);
  }
</style>
