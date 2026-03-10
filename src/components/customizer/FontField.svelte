<script>
  import { onMount } from 'svelte';
  import { fontsByCategory, googleFontsPreviewUrl } from '$lib/google-fonts.js';
  import { fonts as fontsApi } from '$lib/api.js';

  let { key, label, value = '', defaultValue = 'Inter', onchange = () => {} } = $props();

  let selectedFont = $state('');
  let groups = $state(fontsByCategory());
  let fontsLoaded = $state(false);
  let previewLink = null;

  $effect(() => {
    selectedFont = value || defaultValue;
  });

  function loadPreviewFonts() {
    if (previewLink) previewLink.remove();
    const allFonts = Object.values(groups).flat();
    const link = document.createElement('link');
    link.rel = 'stylesheet';
    const families = allFonts
      .map(f => f.name.replace(/ /g, '+') + ':wght@400;700')
      .join('&family=');
    link.href = `https://fonts.googleapis.com/css2?family=${families}&display=swap&text=AaBbCc`;
    link.onload = () => { fontsLoaded = true; };
    document.head.appendChild(link);
    previewLink = link;
  }

  onMount(() => {
    // Load custom fonts from API, merge with curated list
    fontsApi.list().then(data => {
      if (data.fonts?.length) {
        groups = fontsByCategory(data.fonts);
      }
      loadPreviewFonts();
    }).catch(() => {
      // Fallback to curated-only
      loadPreviewFonts();
    });

    return () => { if (previewLink) previewLink.remove(); };
  });

  function handleChange(e) {
    selectedFont = e.target.value;
    onchange(key, e.target.value);
  }

  function resetToDefault() {
    selectedFont = defaultValue;
    onchange(key, defaultValue);
  }

  let isModified = $derived(value && value !== defaultValue);
</script>

<div class="font-field">
  <div class="font-field-label">{label}</div>
  <div class="font-field-controls">
    <div class="font-select-wrap">
      <select class="font-select" value={selectedFont} onchange={handleChange} aria-label={label}>
        <option value="System Default">System Default</option>
        {#each Object.entries(groups) as [category, fonts]}
          <optgroup label={category}>
            {#each fonts as font}
              <option value={font.name} style="font-family: '{font.name}', sans-serif">{font.name}</option>
            {/each}
          </optgroup>
        {/each}
      </select>
    </div>
    {#if isModified}
      <button class="font-reset" onclick={resetToDefault} title="Reset to default" aria-label="Reset {label} to default">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-5.1L1 10"/></svg>
      </button>
    {/if}
  </div>
  <div class="font-preview" style="font-family: '{selectedFont}', sans-serif">
    The quick brown fox jumps over the lazy dog
  </div>
</div>

<style>
  .font-field {
    padding: 10px 0;
    border-bottom: 1px solid var(--border-color);
  }

  .font-field:last-child {
    border-bottom: none;
  }

  .font-field-label {
    font-size: var(--font-size-sm);
    color: var(--text-primary);
    font-weight: 500;
    margin-bottom: 8px;
  }

  .font-field-controls {
    display: flex;
    align-items: center;
    gap: 8px;
  }

  .font-select-wrap {
    flex: 1;
  }

  .font-select {
    width: 100%;
    font-size: 13px;
    padding: 7px 10px;
    border: 1px solid transparent;
    border-radius: var(--radius-md);
    background: var(--bg-secondary);
    color: var(--text-primary);
    cursor: pointer;
    transition: border-color 0.15s;
    appearance: auto;
  }

  .font-select:hover {
    border-color: var(--border-color);
  }

  .font-select:focus {
    outline: none;
    border-color: var(--accent);
  }

  .font-preview {
    margin-top: 8px;
    font-size: 15px;
    color: var(--text-secondary);
    line-height: 1.5;
    padding: 8px 10px;
    background: var(--bg-secondary);
    border-radius: var(--radius-md);
  }

  .font-reset {
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
    flex-shrink: 0;
  }

  .font-reset:hover {
    color: var(--accent);
  }

  .font-reset svg {
    width: 14px;
    height: 14px;
  }
</style>
