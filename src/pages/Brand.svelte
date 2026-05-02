<script>
  import { onMount } from 'svelte';
  import { brand as brandApi, fonts as fontsApi } from '$lib/api.js';
  import { addToast } from '$lib/stores.js';
  import FontField from '$components/customizer/FontField.svelte';
  import ImageField from '$components/customizer/ImageField.svelte';
  import ColorPicker from '$components/ColorPicker.svelte';

  let loading = $state(true);
  let saving = $state(false);
  let dirty = $state(false);

  let brand = $state({ colors: {}, typography: {}, identity: {} });
  let defaults = $state({ colors: {}, typography: {}, identity: {} });
  let scaleOptions = $state([]);
  let computedScale = $state({});

  // ── Manage Fonts modal state ──
  let showFontsModal = $state(false);
  let customFonts = $state([]);
  let newFontName = $state('');
  let newFontCategory = $state('Sans Serif');
  let fontsSaving = $state(false);
  let fontsKey = $state(0);

  async function loadCustomFonts() {
    try {
      const data = await fontsApi.list();
      customFonts = data.fonts || [];
    } catch { /* ignore */ }
  }

  function openFontsModal() {
    loadCustomFonts();
    showFontsModal = true;
  }

  function addCustomFont() {
    const name = newFontName.trim();
    if (!name) return;
    if (customFonts.some(f => f.name.toLowerCase() === name.toLowerCase())) {
      addToast('Font already added', 'error');
      return;
    }
    customFonts = [...customFonts, { name, category: newFontCategory }];
    newFontName = '';
  }

  function removeCustomFont(index) {
    customFonts = customFonts.filter((_, i) => i !== index);
  }

  async function saveCustomFonts() {
    fontsSaving = true;
    try {
      const data = await fontsApi.save(customFonts);
      customFonts = data.fonts || customFonts;
      showFontsModal = false;
      fontsKey++;
      addToast('Fonts saved', 'success');
    } catch (err) {
      addToast('Failed to save fonts: ' + err.message, 'error');
    } finally {
      fontsSaving = false;
    }
  }

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

  function getColor(key) {
    return brand.colors?.[key] || defaults.colors?.[key] || '#000000';
  }

  function handleColorInput(key, e) {
    brand.colors[key] = e.target.value;
    dirty = true;
  }

  function handleHexInput(key, e) {
    const val = e.target.value;
    if (/^#[0-9A-Fa-f]{6}$/.test(val)) {
      brand.colors[key] = val;
      dirty = true;
    }
  }

  function resetColor(key) {
    brand.colors[key] = defaults.colors?.[key] || '#000000';
    dirty = true;
  }

  function isColorModified(key) {
    return brand.colors?.[key] && brand.colors[key] !== (defaults.colors?.[key] || '#000000');
  }

  function handleFontChange(key, value) {
    brand.typography[key] = value;
    dirty = true;
  }

  function handleScaleChange(e) {
    brand.typography.type_scale = e.target.value;
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

  let headingFont = $derived(brand.typography?.heading_font || defaults.typography?.heading_font || 'Inter');
  let bodyFont = $derived(brand.typography?.body_font || defaults.typography?.body_font || 'Inter');

  function contrastRatio(hex1, hex2) {
    function luminance(hex) {
      const r = parseInt(hex.slice(1,3), 16) / 255;
      const g = parseInt(hex.slice(3,5), 16) / 255;
      const b = parseInt(hex.slice(5,7), 16) / 255;
      const sR = r <= 0.03928 ? r / 12.92 : Math.pow((r + 0.055) / 1.055, 2.4);
      const sG = g <= 0.03928 ? g / 12.92 : Math.pow((g + 0.055) / 1.055, 2.4);
      const sB = b <= 0.03928 ? b / 12.92 : Math.pow((b + 0.055) / 1.055, 2.4);
      return 0.2126 * sR + 0.7152 * sG + 0.0722 * sB;
    }
    const l1 = luminance(hex1);
    const l2 = luminance(hex2);
    const lighter = Math.max(l1, l2);
    const darker = Math.min(l1, l2);
    return +((lighter + 0.05) / (darker + 0.05)).toFixed(1);
  }
</script>

<svelte:window onkeydown={handleKeydown} />

<div class="brand-page">
  <div class="page-header">
    <div class="page-header-icon sage">
      <svg viewBox="0 0 324.99 324.99" fill="currentColor"><path d="M307.6,129.885c-11.453-11.447-23.783-16.778-38.805-16.778c-6.189,0-12.056,0.858-17.729,1.688c-5.094,0.745-9.905,1.449-14.453,1.45c-8.27,0-14.197-2.397-19.82-8.017c-10.107-10.101-8.545-20.758-6.569-34.25c2.357-16.096,5.291-36.127-15.101-56.508C183.578,5.932,167.848,0.081,148.372,0.081c-37.296,0-78.367,21.546-99.662,42.829C17.398,74.205,0.1,115.758,0,159.917c-0.1,44.168,17.018,85.656,48.199,116.82c31.077,31.061,72.452,48.168,116.504,48.171c0.005,0,0.007,0,0.013,0c44.315,0,86.02-17.289,117.428-48.681c17.236-17.226,32.142-44.229,38.9-70.471C329.291,173.738,324.517,146.793,307.6,129.885z M309.424,202.764c-6.16,23.915-20.197,49.42-35.763,64.976c-29.145,29.129-67.833,45.17-108.946,45.169c-0.002,0-0.009,0-0.011,0c-40.849-0.003-79.211-15.863-108.023-44.659C27.777,239.36,11.908,200.896,12,159.944c0.092-40.962,16.142-79.512,45.191-108.545c19.071-19.061,57.508-39.317,91.18-39.317c16.18,0,29.056,4.669,38.269,13.877c16.127,16.118,13.981,30.769,11.71,46.28c-2.067,14.116-4.41,30.115,9.96,44.478c7.871,7.866,16.864,11.529,28.304,11.528c5.421-0.001,10.895-0.802,16.189-1.576c5.248-0.768,10.676-1.562,15.992-1.562c7.938,0,18.557,1.508,30.322,13.267C317.724,156.971,313.562,186.699,309.424,202.764z"/><path d="M142.002,43.531c-1.109,0-2.233,0.065-3.342,0.192c-15.859,1.824-27.33,16.199-25.571,32.042c1.613,14.631,13.93,25.665,28.647,25.665c1.105,0,2.226-0.065,3.332-0.191c15.851-1.823,27.326-16.191,25.581-32.031C169.032,54.57,156.716,43.531,142.002,43.531z M143.7,89.317c-0.652,0.075-1.313,0.113-1.963,0.113c-8.59,0-15.778-6.441-16.721-14.985c-1.032-9.296,5.704-17.729,15.016-18.8c0.655-0.075,1.317-0.114,1.971-0.114c8.587,0,15.775,6.446,16.72,14.993C159.747,79.816,153.006,88.247,143.7,89.317z"/><path d="M102.997,113.64c-1.72-7.512-6.261-13.898-12.784-17.984c-4.597-2.881-9.889-4.404-15.304-4.404c-10.051,0-19.254,5.079-24.618,13.587c-4.14,6.566-5.472,14.34-3.75,21.888c1.715,7.52,6.261,13.92,12.799,18.018c4.596,2.88,9.888,4.402,15.303,4.402c10.051,0,19.255-5.078,24.624-13.593C103.401,128.975,104.726,121.193,102.997,113.64z M89.111,129.16c-3.153,5.001-8.563,7.986-14.469,7.986c-3.158,0-6.246-0.889-8.93-2.57c-3.817-2.393-6.471-6.128-7.472-10.518c-1.008-4.417-0.227-8.97,2.2-12.819c3.153-5.001,8.562-7.987,14.468-7.987c3.158,0,6.246,0.89,8.933,2.573c3.806,2.384,6.454,6.11,7.458,10.493C92.312,120.743,91.534,125.306,89.111,129.16z"/><path d="M70.131,173.25c-3.275,0-6.516,0.557-9.63,1.654c-15.055,5.301-23.05,21.849-17.821,36.892c4.032,11.579,14.984,19.358,27.254,19.358c3.276,0,6.517-0.556,9.637-1.652c15.065-5.301,23.053-21.854,17.806-36.896C93.346,181.029,82.397,173.25,70.131,173.25z M75.589,218.182c-1.836,0.646-3.738,0.973-5.655,0.973c-7.168,0-13.566-4.543-15.921-11.302c-3.063-8.814,1.636-18.518,10.476-21.63c1.83-0.645,3.729-0.973,5.643-0.973c7.165,0,13.56,4.542,15.914,11.304C89.12,205.37,84.429,215.072,75.589,218.182z"/><path d="M140.817,229.415c-3.071-1.066-6.266-1.606-9.496-1.606c-12.307,0-23.328,7.804-27.431,19.429c-2.566,7.317-2.131,15.185,1.229,22.151c3.349,6.943,9.204,12.163,16.486,14.696c3.075,1.071,6.274,1.614,9.51,1.614c12.3,0,23.314-7.811,27.409-19.439c2.574-7.31,2.143-15.175-1.216-22.145C153.958,237.165,148.103,231.945,140.817,229.415z M147.206,262.275c-2.407,6.834-8.873,11.425-16.091,11.425c-1.888,0-3.759-0.318-5.563-0.947c-4.253-1.48-7.67-4.524-9.623-8.575c-1.965-4.074-2.219-8.68-0.718-12.957c2.408-6.825,8.883-11.411,16.11-11.411c1.888,0,3.759,0.317,5.561,0.942c4.248,1.475,7.663,4.52,9.616,8.573C148.46,253.399,148.711,257.998,147.206,262.275z"/><path d="M212.332,213.811c-5.466,0-10.81,1.55-15.448,4.479c-13.525,8.521-17.652,26.427-9.193,39.927c5.315,8.445,14.463,13.488,24.469,13.488c5.458,0,10.796-1.545,15.434-4.464c13.541-8.507,17.663-26.419,9.19-39.926C231.486,218.86,222.345,213.811,212.332,213.811z M221.205,257.082c-2.725,1.715-5.853,2.622-9.045,2.622c-5.857,0-11.207-2.946-14.307-7.87c-4.947-7.896-2.513-18.39,5.433-23.395c2.724-1.72,5.852-2.629,9.047-2.629c5.854,0,11.192,2.944,14.283,7.878C231.577,241.597,229.151,252.09,221.205,257.082z"/><path d="M255.384,141.998c-1.06-0.117-2.134-0.176-3.194-0.176c-14.772,0-27.174,11.068-28.846,25.747c-0.876,7.698,1.297,15.266,6.118,21.311c4.812,6.03,11.686,9.821,19.369,10.676c1.053,0.114,2.12,0.173,3.175,0.173c14.754,0,27.164-11.067,28.869-25.748c0.886-7.688-1.277-15.247-6.091-21.288C269.97,146.651,263.082,142.853,255.384,141.998z M268.955,172.602c-1.001,8.624-8.287,15.127-16.948,15.127c-0.621,0-1.251-0.034-1.86-0.101c-4.48-0.498-8.494-2.712-11.303-6.231c-2.819-3.534-4.089-7.963-3.575-12.47c0.98-8.611,8.255-15.104,16.922-15.104c0.623,0,1.256,0.035,1.875,0.104c4.498,0.499,8.523,2.717,11.334,6.244C268.209,163.697,269.472,168.114,268.955,172.602z"/></svg>
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
    <!-- Zone 1: Palette -->
    <section class="brand-zone">
      <div class="brand-zone-header">
        <span class="brand-zone-label">Palette</span>
        <span class="brand-zone-desc">Primary colors used across framework-enabled themes</span>
      </div>
      <div class="brand-zone-columns">
        <div class="brand-zone-left">
          <div class="color-tile-grid">
            {#each Object.entries(colorLabels) as [key, label]}
              {@const color = getColor(key)}
              <div class="color-tile-wrap">
                <ColorPicker value={color} onchange={(v) => { brand.colors[key] = v; dirty = true; }} />
                {#if isColorModified(key)}
                  <button class="color-tile-reset" onclick={(e) => { e.preventDefault(); e.stopPropagation(); resetColor(key); }} title="Reset to default" aria-label="Reset {label}">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-5.1L1 10"/></svg>
                  </button>
                {/if}
                <span class="color-tile-label">{label}</span>
                <span class="color-tile-hex">{color.toUpperCase()}</span>
              </div>
            {/each}
          </div>
        </div>
        <div class="brand-zone-right">
          <div class="palette-preview">
            <div class="palette-bar">
              {#each Object.keys(colorLabels) as key}
                <div class="palette-segment" style="background: {getColor(key)}"></div>
              {/each}
            </div>
            <div class="contrast-pairs">
              <div class="contrast-pair">
                <div class="contrast-sample" style="background: {getColor('background')}; color: {getColor('primary')}">
                  <span class="contrast-text">Primary on Background</span>
                  <span class="contrast-ratio">{contrastRatio(getColor('primary'), getColor('background'))}:1</span>
                </div>
              </div>
              <div class="contrast-pair">
                <div class="contrast-sample" style="background: {getColor('surface')}; color: {getColor('neutral')}">
                  <span class="contrast-text">Neutral on Surface</span>
                  <span class="contrast-ratio">{contrastRatio(getColor('neutral'), getColor('surface'))}:1</span>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- Zone 2: Typography -->
    <section class="brand-zone brand-zone-border">
      <div class="brand-zone-header">
        <div class="brand-zone-header-row">
          <div>
            <span class="brand-zone-label">Typography</span>
            <span class="brand-zone-desc">Fonts and type scale ratio for headings and body text</span>
          </div>
          <button class="manage-fonts-link" onclick={openFontsModal}>Manage fonts</button>
        </div>
      </div>
      <div class="brand-zone-columns">
        <div class="brand-zone-left">
          {#key fontsKey}
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
          {/key}
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
        </div>
        <div class="brand-zone-right">
          <div class="type-specimen">
            <h3 class="specimen-heading" style="font-family: '{headingFont}', sans-serif">
              The quick brown fox
            </h3>
            <p class="specimen-subheading" style="font-family: '{headingFont}', sans-serif">
              Jumps over the lazy dog
            </p>
            <p class="specimen-body" style="font-family: '{bodyFont}', sans-serif">
              Good typography makes the content shine. Clean type hierarchy guides the reader's eye through headings, subheadings, and body text with clarity and purpose.
            </p>
            <div class="specimen-divider"></div>
            <div class="specimen-scale">
              {#each Object.entries(computedScale) as [token, rem]}
                <div class="specimen-scale-row">
                  <span class="specimen-scale-token">{token}</span>
                  <span class="specimen-scale-size">{rem}rem / {Math.round(rem * 16)}px</span>
                  <span
                    class="specimen-scale-demo"
                    style="font-size: {rem}rem; font-family: '{headingFont}', sans-serif"
                  >Aa</span>
                </div>
              {/each}
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- Zone 3: Identity -->
    <section class="brand-zone brand-zone-border">
      <div class="brand-zone-header">
        <span class="brand-zone-label">Identity</span>
        <span class="brand-zone-desc">Logo and favicon — synced to global template tags</span>
      </div>
      <div class="identity-grid">
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
      </div>
      <p class="brand-identity-hint">
        These sync to <code>{'<img data-outpost="site_logo" data-scope="global" data-type="image" />'}</code> and <code>{'<img data-outpost="site_favicon" data-scope="global" data-type="image" />'}</code> global tags.
      </p>
    </section>
  {/if}
</div>

{#if showFontsModal}
  <div class="modal-overlay" onclick={() => showFontsModal = false} role="presentation">
    <div class="modal-panel" onclick={(e) => e.stopPropagation()} role="dialog" aria-label="Manage Google Fonts">
      <div class="modal-header">
        <h2 class="modal-title">Manage Google Fonts</h2>
        <button class="modal-close" onclick={() => showFontsModal = false} aria-label="Close">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg>
        </button>
      </div>
      <div class="modal-body">
        <p class="fonts-helper-text">Add fonts from <a href="https://fonts.google.com" target="_blank" rel="noopener">fonts.google.com</a>. Enter the exact font name as shown on the site.</p>
        <div class="font-add-row">
          <input
            type="text"
            class="font-add-input"
            placeholder="Font name, e.g. Lexend"
            bind:value={newFontName}
            onkeydown={(e) => { if (e.key === 'Enter') addCustomFont(); }}
          />
          <select class="font-add-category" bind:value={newFontCategory}>
            <option>Sans Serif</option>
            <option>Serif</option>
            <option>Display</option>
            <option>Monospace</option>
          </select>
          <button class="btn btn-primary btn-sm" onclick={addCustomFont} disabled={!newFontName.trim()}>Add</button>
        </div>
        {#if customFonts.length}
          <ul class="custom-font-list">
            {#each customFonts as font, i}
              <li class="custom-font-item">
                <span class="custom-font-name">{font.name}</span>
                <span class="custom-font-cat">{font.category}</span>
                <button class="custom-font-remove" onclick={() => removeCustomFont(i)} title="Remove" aria-label="Remove {font.name}">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg>
                </button>
              </li>
            {/each}
          </ul>
        {:else}
          <p class="fonts-empty">No custom fonts added yet.</p>
        {/if}
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" onclick={() => showFontsModal = false}>Cancel</button>
        <button class="btn btn-primary" onclick={saveCustomFonts} disabled={fontsSaving}>
          {fontsSaving ? 'Saving...' : 'Save'}
        </button>
      </div>
    </div>
  </div>
{/if}

<style>
  .brand-page {
    max-width: var(--content-width);
  }

  /* ── Zone structure ── */
  .brand-zone {
    margin-bottom: 40px;
  }

  .brand-zone-border {
    padding-top: 40px;
    border-top: 1px solid var(--border-color);
  }

  .brand-zone-header {
    margin-bottom: 20px;
  }

  .brand-zone-label {
    display: block;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: var(--dim);
    margin-bottom: 4px;
  }

  .brand-zone-desc {
    display: block;
    font-size: 13px;
    color: var(--dim);
    line-height: 1.4;
  }

  .brand-zone-columns {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 32px;
    align-items: start;
  }

  /* ── Color tiles ── */
  .color-tile-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 12px;
  }

  .color-tile-wrap {
    display: flex;
    flex-direction: column;
    gap: 4px;
  }

  .color-tile {
    position: relative;
    display: block;
    width: 100%;
    height: 72px;
    border-radius: var(--radius-lg);
    cursor: pointer;
    transition: box-shadow 0.15s;
    overflow: hidden;
  }

  .color-tile:hover {
    box-shadow: 0 0 0 2px var(--purple);
  }

  .color-tile-input {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    opacity: 0;
    cursor: pointer;
    border: none;
    padding: 0;
  }

  .color-tile-reset {
    position: absolute;
    top: 6px;
    right: 6px;
    width: 22px;
    height: 22px;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 0;
    background: rgba(0, 0, 0, 0.5);
    border: none;
    border-radius: var(--radius-sm);
    color: #fff;
    cursor: pointer;
    z-index: 1;
  }

  .color-tile-reset svg {
    width: 12px;
    height: 12px;
  }

  .color-tile:hover .color-tile-reset {
    display: flex;
  }

  .color-tile-label {
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--dim);
  }

  .color-tile-hex {
    font-size: 11px;
    font-family: var(--font-mono);
    color: var(--sec);
  }

  /* ── Palette preview ── */
  .palette-preview {
    background: var(--raised);
    border-radius: var(--radius-lg);
    padding: 20px;
  }

  .palette-bar {
    display: flex;
    height: 56px;
    border-radius: var(--radius-md);
    overflow: hidden;
    margin-bottom: 20px;
  }

  .palette-segment {
    flex: 1;
    transition: background 0.2s;
  }

  .contrast-pairs {
    display: flex;
    flex-direction: column;
    gap: 10px;
  }

  .contrast-sample {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 16px;
    border-radius: var(--radius-md);
    transition: all 0.2s;
  }

  .contrast-text {
    font-size: 13px;
    font-weight: 500;
  }

  .contrast-ratio {
    font-size: 12px;
    font-family: var(--font-mono);
    opacity: 0.7;
  }

  /* ── Type specimen ── */
  .type-specimen {
    background: var(--raised);
    border-radius: var(--radius-lg);
    padding: 24px;
  }

  .specimen-heading {
    font-size: 1.953rem;
    font-weight: 700;
    margin: 0 0 6px;
    line-height: 1.2;
    color: var(--text);
  }

  .specimen-subheading {
    font-size: 1.125rem;
    font-weight: 600;
    margin: 0 0 12px;
    line-height: 1.3;
    color: var(--sec);
  }

  .specimen-body {
    font-size: 1rem;
    line-height: 1.6;
    margin: 0;
    color: var(--sec);
  }

  .specimen-divider {
    height: 1px;
    background: var(--border-color);
    margin: 16px 0;
  }

  .specimen-scale {
    display: flex;
    flex-direction: column;
    gap: 4px;
  }

  .specimen-scale-row {
    display: flex;
    align-items: baseline;
    gap: 12px;
    padding: 3px 0;
  }

  .specimen-scale-token {
    width: 72px;
    flex-shrink: 0;
    font-size: 11px;
    font-family: var(--font-mono);
    color: var(--dim);
  }

  .specimen-scale-size {
    width: 100px;
    flex-shrink: 0;
    font-size: 11px;
    font-family: var(--font-mono);
    color: var(--sec);
  }

  .specimen-scale-demo {
    line-height: 1.2;
    color: var(--text);
    font-weight: 600;
  }

  /* ── Scale field ── */
  .brand-scale-field {
    padding: 10px 0;
  }

  .brand-scale-header {
    margin-bottom: 8px;
  }

  .brand-scale-label {
    font-size: var(--font-size-sm);
    font-weight: 500;
    color: var(--text);
  }

  .brand-scale-select {
    width: 100%;
    font-size: 13px;
    padding: 7px 10px;
    border: 1px solid transparent;
    border-radius: var(--radius-md);
    background: var(--raised);
    color: var(--text);
    cursor: pointer;
    transition: border-color 0.15s;
    appearance: auto;
  }

  .brand-scale-select:hover {
    border-color: var(--border-color);
  }

  .brand-scale-select:focus {
    outline: none;
    border-color: var(--purple);
  }

  /* ── Identity ── */
  .identity-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 32px;
  }

  .brand-identity-hint {
    margin-top: 16px;
    font-size: 12px;
    color: var(--dim);
    line-height: 1.5;
  }

  .brand-identity-hint code {
    font-family: var(--font-mono);
    font-size: 11px;
    padding: 1px 5px;
    background: var(--raised);
    border-radius: var(--radius-sm);
    color: var(--sec);
  }

  /* ── Manage Fonts ── */
  .brand-zone-header-row {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
  }

  .manage-fonts-link {
    background: none;
    border: none;
    color: var(--dim);
    font-size: 12px;
    cursor: pointer;
    padding: 0;
    white-space: nowrap;
    transition: color 0.15s;
  }

  .manage-fonts-link:hover {
    color: var(--purple);
  }

  /* ── Fonts Modal ── */
  .modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.4);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 1000;
  }

  .modal-panel {
    background: var(--bg);
    border-radius: var(--radius-lg);
    width: 480px;
    max-width: 90vw;
    max-height: 80vh;
    display: flex;
    flex-direction: column;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.15);
  }

  .modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 20px 24px 0;
  }

  .modal-title {
    font-size: 16px;
    font-weight: 600;
    margin: 0;
    color: var(--text);
  }

  .modal-close {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    padding: 0;
    background: none;
    border: none;
    border-radius: var(--radius-sm);
    color: var(--dim);
    cursor: pointer;
  }

  .modal-close:hover {
    color: var(--text);
  }

  .modal-close svg {
    width: 16px;
    height: 16px;
  }

  .modal-body {
    padding: 16px 24px;
    overflow-y: auto;
    flex: 1;
  }

  .modal-footer {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    padding: 16px 24px;
    border-top: 1px solid var(--border-color);
  }

  .fonts-helper-text {
    font-size: 13px;
    color: var(--sec);
    margin: 0 0 16px;
    line-height: 1.5;
  }

  .fonts-helper-text a {
    color: var(--purple);
    text-decoration: none;
  }

  .fonts-helper-text a:hover {
    text-decoration: underline;
  }

  .font-add-row {
    display: flex;
    gap: 8px;
    margin-bottom: 16px;
  }

  .font-add-input {
    flex: 1;
    font-size: 13px;
    padding: 7px 10px;
    border: 1px solid var(--border-color);
    border-radius: var(--radius-md);
    background: var(--bg);
    color: var(--text);
  }

  .font-add-input:focus {
    outline: none;
    border-color: var(--purple);
  }

  .font-add-category {
    font-size: 13px;
    padding: 7px 8px;
    border: 1px solid var(--border-color);
    border-radius: var(--radius-md);
    background: var(--bg);
    color: var(--text);
    appearance: auto;
  }

  .btn-sm {
    padding: 6px 12px;
    font-size: 12px;
  }

  .custom-font-list {
    list-style: none;
    margin: 0;
    padding: 0;
  }

  .custom-font-item {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 0;
    border-bottom: 1px solid var(--border-color);
  }

  .custom-font-item:last-child {
    border-bottom: none;
  }

  .custom-font-name {
    flex: 1;
    font-size: 13px;
    font-weight: 500;
    color: var(--text);
  }

  .custom-font-cat {
    font-size: 11px;
    color: var(--dim);
    text-transform: uppercase;
    letter-spacing: 0.04em;
  }

  .custom-font-remove {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 24px;
    height: 24px;
    padding: 0;
    background: none;
    border: none;
    border-radius: var(--radius-sm);
    color: var(--dim);
    cursor: pointer;
  }

  .custom-font-remove:hover {
    color: var(--danger, #e53e3e);
  }

  .custom-font-remove svg {
    width: 14px;
    height: 14px;
  }

  .fonts-empty {
    font-size: 13px;
    color: var(--dim);
    text-align: center;
    padding: 20px 0;
    margin: 0;
  }

  /* ── Responsive ── */
  @media (max-width: 768px) {
    .brand-zone-columns {
      grid-template-columns: 1fr;
      gap: 20px;
    }

    .color-tile-grid {
      grid-template-columns: repeat(2, 1fr);
    }

    .identity-grid {
      grid-template-columns: 1fr;
      gap: 20px;
    }
  }
</style>
