<script>
  let { key, label, value = '', defaultValue = '#000000', onchange = () => {} } = $props();

  let hexInput = $state('');

  $effect(() => {
    hexInput = value || defaultValue;
  });

  function handleColorInput(e) {
    hexInput = e.target.value;
    onchange(key, e.target.value);
  }

  function handleHexInput(e) {
    const val = e.target.value;
    hexInput = val;
    if (/^#[0-9A-Fa-f]{6}$/.test(val)) {
      onchange(key, val);
    }
  }

  function handleHexBlur() {
    if (!/^#[0-9A-Fa-f]{6}$/.test(hexInput)) {
      hexInput = value || defaultValue;
    }
  }

  function resetToDefault() {
    hexInput = defaultValue;
    onchange(key, defaultValue);
  }

  let isModified = $derived(value && value !== defaultValue);
</script>

<div class="color-field">
  <div class="color-field-label">{label}</div>
  <div class="color-field-controls">
    <input
      type="color"
      class="color-swatch"
      value={hexInput}
      oninput={handleColorInput}
      aria-label={label}
    />
    <input
      type="text"
      class="color-hex"
      value={hexInput}
      oninput={handleHexInput}
      onblur={handleHexBlur}
      maxlength="7"
      spellcheck="false"
      aria-label="{label} hex value"
    />
    {#if isModified}
      <button class="color-reset" onclick={resetToDefault} title="Reset to default" aria-label="Reset {label} to default">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-5.1L1 10"/></svg>
      </button>
    {/if}
  </div>
</div>

<style>
  .color-field {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 10px 0;
    border-bottom: 1px solid var(--border-color);
  }

  .color-field:last-child {
    border-bottom: none;
  }

  .color-field-label {
    font-size: var(--font-size-sm);
    color: var(--text-primary);
    font-weight: 500;
  }

  .color-field-controls {
    display: flex;
    align-items: center;
    gap: 8px;
  }

  .color-swatch {
    width: 32px;
    height: 32px;
    border: 1px solid var(--border-color);
    border-radius: var(--radius-md);
    padding: 2px;
    cursor: pointer;
    background: none;
  }

  .color-swatch::-webkit-color-swatch-wrapper {
    padding: 0;
  }

  .color-swatch::-webkit-color-swatch {
    border: none;
    border-radius: 4px;
  }

  .color-swatch::-moz-color-swatch {
    border: none;
    border-radius: 4px;
  }

  .color-hex {
    width: 80px;
    font-size: 13px;
    font-family: var(--font-mono);
    padding: 6px 8px;
    border: 1px solid transparent;
    border-radius: var(--radius-md);
    background: var(--bg-secondary);
    color: var(--text-primary);
    text-transform: uppercase;
    transition: border-color 0.15s;
  }

  .color-hex:hover {
    border-color: var(--border-color);
  }

  .color-hex:focus {
    outline: none;
    border-color: var(--accent);
  }

  .color-reset {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 24px;
    height: 24px;
    padding: 0;
    background: none;
    border: none;
    border-radius: var(--radius-sm);
    color: var(--text-tertiary);
    cursor: pointer;
    transition: color 0.15s;
  }

  .color-reset:hover {
    color: var(--accent);
  }

  .color-reset svg {
    width: 14px;
    height: 14px;
  }
</style>
