<script>
  import { onMount } from 'svelte';
  import { brand as brandApi } from '$lib/api.js';
  import { addToast } from '$lib/stores.js';
  import ColorField from '$components/customizer/ColorField.svelte';
  import FontField from '$components/customizer/FontField.svelte';
  import ImageField from '$components/customizer/ImageField.svelte';

  let loading = $state(true);
  let saving = $state(false);
  let dirty = $state(false);

  let brand = $state({ colors: {}, typography: {}, identity: {} });
  let defaults = $state({ colors: {}, typography: {}, identity: {} });
  let scaleOptions = $state([]);
  let computedScale = $state({});

  // Section collapse state
  let colorsOpen = $state(true);
  let typographyOpen = $state(true);
  let identityOpen = $state(true);

  const colorLabels = {
    primary: 'Primary',
    secondary: 'Secondary',
    accent: 'Accent',
    neutral: 'Neutral',
    background: 'Background',
    surface: 'Surface',
  };

  onMount(load);

  async function load() {
    loading = true;
    try {
      const data = await brandApi.get();
      brand = data.brand;
      defaults = data.defaults;
      scaleOptions = data.scale_options || [];
      computedScale = data.computed_scale || {};
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      loading = false;
    }
  }

  function handleColorChange(key, value) {
    brand.colors[key] = value;
    dirty = true;
  }

  function handleFontChange(key, value) {
    brand.typography[key] = value;
    dirty = true;
  }

  function handleScaleChange(e) {
    brand.typography.type_scale = e.target.value;
    // Recompute preview locally
    const ratio = parseFloat(e.target.value);
    const base = 1.0;
    computedScale = {
      'text-xs': +(base / (ratio * ratio)).toFixed(3),
      'text-sm': +(base / ratio).toFixed(3),
      'text-base': base,
      'text-lg': +(base * ratio).toFixed(3),
      'text-xl': +(base * ratio * ratio).toFixed(3),
      'text-2xl': +(base * Math.pow(ratio, 3)).toFixed(3),
      'text-3xl': +(base * Math.pow(ratio, 4)).toFixed(3),
      'text-4xl': +(base * Math.pow(ratio, 5)).toFixed(3),
      'text-5xl': +(base * Math.pow(ratio, 6)).toFixed(3),
    };
    dirty = true;
  }

  function handleIdentityChange(key, value) {
    brand.identity[key] = value;
    dirty = true;
  }

  async function handleSave() {
    saving = true;
    try {
      const data = await brandApi.save(brand);
      brand = data.brand;
      computedScale = data.computed_scale || {};
      dirty = false;
      addToast('Brand saved', 'success');
    } catch (err) {
      addToast('Failed to save: ' + err.message, 'error');
    } finally {
      saving = false;
    }
  }

  function handleKeydown(e) {
    if ((e.metaKey || e.ctrlKey) && e.key === 's') {
      e.preventDefault();
      if (dirty && !saving) handleSave();
    }
  }

  let currentScaleLabel = $derived(() => {
    const opt = scaleOptions.find(o => o.value === brand.typography?.type_scale);
    return opt ? opt.label : '';
  });
</script>

<svelte:window onkeydown={handleKeydown} />

<div class="brand-page">
  <div class="page-header">
    <div class="page-header-icon sage">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>
    </div>
    <div class="page-header-content">
      <h1 class="page-title">Brand</h1>
      <p class="page-subtitle">Site-wide identity: colors, typography, and logos</p>
    </div>
    <div class="page-header-actions">
      <button
        class="btn btn-primary"
        onclick={handleSave}
        disabled={!dirty || saving}
      >
        {saving ? 'Saving...' : 'Save'}
      </button>
    </div>
  </div>

  {#if loading}
    <div class="loading-overlay"><div class="spinner"></div></div>
  {:else}
    <!-- Colors Section -->
    <section class="brand-section">
      <button class="brand-section-toggle" onclick={() => { colorsOpen = !colorsOpen; }}>
        <svg class="brand-section-chevron" class:open={colorsOpen} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
        <div>
          <h2 class="brand-section-title">Colors</h2>
          <p class="brand-section-desc">Primary palette used across framework-enabled themes</p>
        </div>
      </button>
      {#if colorsOpen}
        <div class="brand-section-body">
          <div class="brand-color-grid">
            {#each Object.entries(colorLabels) as [key, label]}
              <ColorField
                {key}
                {label}
                value={brand.colors?.[key] || ''}
                defaultValue={defaults.colors?.[key] || '#000000'}
                onchange={handleColorChange}
              />
            {/each}
          </div>
          <div class="brand-color-preview">
            {#each Object.entries(colorLabels) as [key, label]}
              <div
                class="brand-swatch"
                style="background: {brand.colors?.[key] || defaults.colors?.[key]}"
                title="{label}: {brand.colors?.[key] || defaults.colors?.[key]}"
              ></div>
            {/each}
          </div>
        </div>
      {/if}
    </section>

    <!-- Typography Section -->
    <section class="brand-section">
      <button class="brand-section-toggle" onclick={() => { typographyOpen = !typographyOpen; }}>
        <svg class="brand-section-chevron" class:open={typographyOpen} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
        <div>
          <h2 class="brand-section-title">Typography</h2>
          <p class="brand-section-desc">Fonts and type scale ratio for headings and body text</p>
        </div>
      </button>
      {#if typographyOpen}
        <div class="brand-section-body">
          <FontField
            key="heading_font"
            label="Heading Font"
            value={brand.typography?.heading_font || ''}
            defaultValue={defaults.typography?.heading_font || 'Inter'}
            onchange={handleFontChange}
          />
          <FontField
            key="body_font"
            label="Body Font"
            value={brand.typography?.body_font || ''}
            defaultValue={defaults.typography?.body_font || 'Inter'}
            onchange={handleFontChange}
          />

          <div class="brand-scale-field">
            <div class="brand-scale-header">
              <span class="brand-scale-label">Type Scale Ratio</span>
            </div>
            <select
              class="brand-scale-select"
              value={brand.typography?.type_scale || '1.25'}
              onchange={handleScaleChange}
            >
              {#each scaleOptions as opt}
                <option value={opt.value}>{opt.label}</option>
              {/each}
            </select>
          </div>

          <!-- Type Scale Preview -->
          <div class="brand-scale-preview">
            <div class="brand-scale-preview-title">Computed Scale</div>
            <div class="brand-scale-samples">
              {#each Object.entries(computedScale) as [token, rem]}
                <div class="brand-scale-row">
                  <span class="brand-scale-token">{token}</span>
                  <span class="brand-scale-size">{rem}rem / {Math.round(rem * 16)}px</span>
                  <span
                    class="brand-scale-demo"
                    style="font-size: {rem}rem; font-family: '{brand.typography?.heading_font || 'Inter'}', sans-serif"
                  >Aa</span>
                </div>
              {/each}
            </div>
          </div>
        </div>
      {/if}
    </section>

    <!-- Identity Section -->
    <section class="brand-section">
      <button class="brand-section-toggle" onclick={() => { identityOpen = !identityOpen; }}>
        <svg class="brand-section-chevron" class:open={identityOpen} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
        <div>
          <h2 class="brand-section-title">Identity</h2>
          <p class="brand-section-desc">Logo and favicon — synced to global template tags</p>
        </div>
      </button>
      {#if identityOpen}
        <div class="brand-section-body">
          <ImageField
            key="logo"
            label="Logo"
            value={brand.identity?.logo || ''}
            onchange={handleIdentityChange}
          />
          <ImageField
            key="favicon"
            label="Favicon"
            value={brand.identity?.favicon || ''}
            onchange={handleIdentityChange}
          />
          <p class="brand-identity-hint">
            These sync to <code>{'{{ @site_logo | image }}'}</code> and <code>{'{{ @site_favicon | image }}'}</code> global tags.
          </p>
        </div>
      {/if}
    </section>
  {/if}
</div>

<style>
  .brand-page {
    max-width: var(--content-width);
  }

  /* Sections */
  .brand-section {
    margin-bottom: 8px;
  }

  .brand-section-toggle {
    display: flex;
    align-items: center;
    gap: 10px;
    width: 100%;
    padding: 14px 0;
    background: none;
    border: none;
    border-bottom: 1px solid var(--border-color);
    cursor: pointer;
    text-align: left;
    color: var(--text-primary);
  }

  .brand-section-toggle:hover .brand-section-title {
    color: var(--accent);
  }

  .brand-section-chevron {
    width: 16px;
    height: 16px;
    flex-shrink: 0;
    color: var(--text-tertiary);
    transition: transform 0.15s;
  }

  .brand-section-chevron.open {
    transform: rotate(90deg);
  }

  .brand-section-title {
    font-size: 14px;
    font-weight: 600;
    margin: 0;
    line-height: 1.3;
    transition: color 0.15s;
  }

  .brand-section-desc {
    font-size: 12px;
    color: var(--text-tertiary);
    margin: 2px 0 0;
    line-height: 1.3;
  }

  .brand-section-body {
    padding: 12px 0 20px 26px;
  }

  /* Color preview strip */
  .brand-color-preview {
    display: flex;
    gap: 4px;
    margin-top: 16px;
    border-radius: var(--radius-lg);
    overflow: hidden;
    height: 40px;
  }

  .brand-swatch {
    flex: 1;
    transition: background 0.2s;
  }

  /* Type scale */
  .brand-scale-field {
    padding: 10px 0;
    border-bottom: 1px solid var(--border-color);
  }

  .brand-scale-header {
    margin-bottom: 8px;
  }

  .brand-scale-label {
    font-size: var(--font-size-sm);
    font-weight: 500;
    color: var(--text-primary);
  }

  .brand-scale-select {
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

  .brand-scale-select:hover {
    border-color: var(--border-color);
  }

  .brand-scale-select:focus {
    outline: none;
    border-color: var(--accent);
  }

  .brand-scale-preview {
    margin-top: 16px;
    padding: 16px;
    background: var(--bg-secondary);
    border-radius: var(--radius-lg);
  }

  .brand-scale-preview-title {
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--text-tertiary);
    margin-bottom: 12px;
  }

  .brand-scale-samples {
    display: flex;
    flex-direction: column;
    gap: 6px;
  }

  .brand-scale-row {
    display: flex;
    align-items: baseline;
    gap: 12px;
    padding: 4px 0;
  }

  .brand-scale-token {
    width: 72px;
    flex-shrink: 0;
    font-size: 11px;
    font-family: var(--font-mono);
    color: var(--text-tertiary);
  }

  .brand-scale-size {
    width: 100px;
    flex-shrink: 0;
    font-size: 11px;
    font-family: var(--font-mono);
    color: var(--text-secondary);
  }

  .brand-scale-demo {
    line-height: 1.2;
    color: var(--text-primary);
    font-weight: 600;
  }

  /* Identity hint */
  .brand-identity-hint {
    margin-top: 12px;
    font-size: 12px;
    color: var(--text-tertiary);
    line-height: 1.5;
  }

  .brand-identity-hint code {
    font-family: var(--font-mono);
    font-size: 11px;
    padding: 1px 5px;
    background: var(--bg-secondary);
    border-radius: var(--radius-sm);
    color: var(--text-secondary);
  }
</style>
