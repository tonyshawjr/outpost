<script>
  let {
    pairs = $bindable([]),
    keyPlaceholder = 'Key',
    valuePlaceholder = 'Value',
    onChange = () => {},
  } = $props();

  function addPair() {
    pairs = [...pairs, { key: '', value: '' }];
    onChange();
  }

  function removePair(idx) {
    pairs = pairs.filter((_, i) => i !== idx);
    onChange();
  }

  function updatePair(idx, field, value) {
    pairs = pairs.map((p, i) => (i === idx ? { ...p, [field]: value } : p));
    onChange();
  }
</script>

<div class="kv-editor">
  {#each pairs as pair, i (i)}
    <div class="kv-row">
      <input
        type="text"
        class="kv-input"
        placeholder={keyPlaceholder}
        value={pair.key}
        oninput={(e) => updatePair(i, 'key', e.target.value)}
      />
      <input
        type="text"
        class="kv-input"
        placeholder={valuePlaceholder}
        value={pair.value}
        oninput={(e) => updatePair(i, 'value', e.target.value)}
      />
      <button class="kv-remove" onclick={() => removePair(i)} title="Remove">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
        </svg>
      </button>
    </div>
  {/each}

  <button class="kv-add" onclick={addPair}>+ Add</button>
</div>

<style>
  .kv-editor {
    display: flex;
    flex-direction: column;
    gap: 6px;
  }

  .kv-row {
    display: flex;
    align-items: center;
    gap: 8px;
  }

  .kv-input {
    flex: 1;
    padding: 6px 8px;
    font-size: 13px;
    color: var(--text);
    background: transparent;
    border: 1px solid transparent;
    border-radius: var(--radius-sm);
    transition: border-color 0.15s;
  }

  .kv-input:hover {
    border-color: var(--border);
  }

  .kv-input:focus {
    border-color: var(--accent);
    outline: none;
  }

  .kv-input::placeholder {
    color: var(--text-muted);
  }

  .kv-remove {
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    padding: 0;
    background: none;
    border: none;
    color: var(--text-muted);
    cursor: pointer;
    border-radius: var(--radius-sm);
    transition: color 0.15s, background 0.15s;
  }

  .kv-remove:hover {
    color: var(--text);
    background: var(--bg-secondary);
  }

  .kv-add {
    align-self: flex-start;
    padding: 4px 0;
    font-size: 12px;
    font-weight: 500;
    color: var(--accent);
    background: none;
    border: none;
    cursor: pointer;
    transition: opacity 0.15s;
  }

  .kv-add:hover {
    opacity: 0.8;
  }
</style>
