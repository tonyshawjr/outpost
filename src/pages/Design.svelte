<script>
  import { onMount } from 'svelte';
  import { navigate, addToast } from '$lib/stores.js';
  import { brandSettings } from '$lib/api.js';
  import Checkbox from '$components/Checkbox.svelte';
  import ColorPicker from '$components/ColorPicker.svelte';
  import { Home, FileText as FileTextIcon, Save, X, Monitor, Smartphone } from 'lucide-svelte';

  // Top bar state
  let previewTab = $state('homepage'); // 'homepage' | 'post'
  let deviceMode = $state('desktop'); // 'desktop' | 'mobile'
  let deviceAnim = $state('');
  let previewFrame = $state(null);

  function toggleDevice() {
    const goingMobile = deviceMode === 'desktop';
    deviceAnim = goingMobile ? 'to-down' : 'to-up';
    deviceMode = goingMobile ? 'mobile' : 'desktop';
    setTimeout(() => { deviceAnim = ''; }, 450);
    // Trigger responsive recalculation in iframe
    setTimeout(() => {
      try { previewFrame?.contentWindow?.dispatchEvent(new Event('resize')); } catch {}
    }, 300);
  }

  // Right panel tabs
  let panelTab = $state('brand'); // 'brand' | 'layout'

  // Blueprint + saved data from API
  let blueprint = $state({});
  let savedBrand = $state({});

  // Brand settings
  let accentColor = $state('#7C3AED');
  let headingFont = $state('Inter');
  let bodyFont = $state('Inter');

  // Popular fonts (always shown)
  const popularFonts = [
    'Inter', 'Roboto', 'Open Sans', 'Lato', 'Montserrat', 'Poppins',
    'Raleway', 'Nunito', 'Source Sans 3', 'Work Sans',
    'Playfair Display', 'Merriweather', 'Lora', 'PT Serif', 'Libre Baskerville',
    'DM Sans', 'Manrope', 'Space Grotesk', 'Outfit', 'Plus Jakarta Sans',
  ];

  // Custom fonts added by user
  let customFonts = $state([]);
  let googleFonts = $derived([...popularFonts, ...customFonts]);
  let showAddFont = $state(false);
  let newFontName = $state('');

  function addCustomFont() {
    const name = newFontName.trim();
    if (name && !googleFonts.includes(name)) {
      customFonts = [...customFonts, name];
      loadGoogleFont(name);
      newFontName = '';
      showAddFont = false;
    }
  }

  // Load Google Font dynamically
  let loadedFonts = $state(new Set(['Inter']));

  function loadGoogleFont(fontName) {
    if (fontName === 'Default' || loadedFonts.has(fontName)) return;
    const link = document.createElement('link');
    link.rel = 'stylesheet';
    link.href = `https://fonts.googleapis.com/css2?family=${fontName.replace(/ /g, '+')}:wght@400;500;600;700;800&display=swap`;
    document.head.appendChild(link);
    loadedFonts = new Set([...loadedFonts, fontName]);
  }

  // Watch font changes and load them
  $effect(() => { loadGoogleFont(headingFont); });
  $effect(() => { loadGoogleFont(bodyFont); });

  // Resolved font families for the preview
  let headingFontFamily = $derived(headingFont === 'Default' ? 'Inter, sans-serif' : `'${headingFont}', sans-serif`);
  let bodyFontFamily = $derived(bodyFont === 'Default' ? 'Inter, sans-serif' : `'${bodyFont}', sans-serif`);

  // Theme settings — site wide
  let navLayout = $state('logo-left');
  let siteBgColor = $state('#ffffff');
  let headerFooterColor = $state('#1a1a2e');

  // Theme settings — homepage
  let headerStyle = $state('landing');
  let headerText = $state('');
  let bgImage = $state(true);
  let postFeedStyle = $state('grid');
  let showFeedImages = $state(true);
  let showAuthor = $state(true);
  let showPublishDate = $state(true);

  // Theme settings — post
  let showPostMeta = $state(true);
  let enableDropCaps = $state(false);
  let showRelated = $state(true);

  // Load settings from blueprint.json and saved overrides on mount
  onMount(async () => {
    try {
      const data = await brandSettings.get();
      blueprint = data.blueprint || {};
      savedBrand = data.saved || {};

      // Apply saved values or blueprint defaults
      accentColor = savedBrand.accent_color || blueprint.brand?.accent_color || '#7C3AED';
      headingFont = savedBrand.heading_font || blueprint.brand?.heading_font || 'Inter';
      bodyFont = savedBrand.body_font || blueprint.brand?.body_font || 'Inter';
      navLayout = savedBrand.nav_layout || blueprint.settings?.nav_layout?.default || 'logo-left';
      headerStyle = savedBrand.header_style || blueprint.settings?.header_style?.default || 'landing';
      siteBgColor = savedBrand.site_bg_color || '#ffffff';
      headerFooterColor = savedBrand.header_footer_color || '#1a1a2e';
      headerText = savedBrand.header_text || '';
      bgImage = savedBrand.bg_image ?? true;
      postFeedStyle = savedBrand.post_feed_style || 'grid';
      showFeedImages = savedBrand.show_feed_images ?? true;
      showAuthor = savedBrand.show_author ?? true;
      showPublishDate = savedBrand.show_publish_date ?? true;
      showPostMeta = savedBrand.show_post_meta ?? true;
      enableDropCaps = savedBrand.enable_drop_caps ?? false;
      showRelated = savedBrand.show_related ?? true;
    } catch {
      // Use defaults on error
    }
    // Wait a tick before enabling auto-save to avoid triggering on initial load
    setTimeout(() => { initialized = true; }, 500);
  });

  let initialized = false;

  function getAllSettings() {
    return {
      accent_color: accentColor,
      heading_font: headingFont,
      body_font: bodyFont,
      nav_layout: navLayout,
      header_style: headerStyle,
      site_bg_color: siteBgColor,
      header_footer_color: headerFooterColor,
      header_text: headerText,
      bg_image: bgImage,
      post_feed_style: postFeedStyle,
      show_feed_images: showFeedImages,
      show_author: showAuthor,
      show_publish_date: showPublishDate,
      show_post_meta: showPostMeta,
      enable_drop_caps: enableDropCaps,
      show_related: showRelated,
    };
  }

  // Live DOM manipulation for CSS-variable changes (no iframe reload)
  function updatePreviewLive() {
    try {
      const doc = previewFrame?.contentDocument;
      if (!doc) return false;
      const root = doc.documentElement;

      // Update CSS variables
      root.style.setProperty('--kenii-primary', accentColor);
      root.style.setProperty('--kenii-primary-dark', accentColor);
      root.style.setProperty('--kenii-header-footer', headerFooterColor);
      root.style.setProperty('--kenii-bg', siteBgColor);
      doc.body.style.backgroundColor = siteBgColor;

      // Update fonts
      const headingRule = `"${headingFont}", sans-serif`;
      const bodyRule = `"${bodyFont}", sans-serif`;
      root.style.setProperty('--kenii-font-heading', headingRule);
      root.style.setProperty('--kenii-font-body', bodyRule);

      // Apply font directly to elements (override Tailwind)
      doc.body.style.fontFamily = bodyRule;
      doc.querySelectorAll('h1,h2,h3,h4,h5,h6').forEach(el => {
        el.style.fontFamily = headingRule;
      });

      // Load Google Font if needed
      [headingFont, bodyFont].forEach(font => {
        if (font === 'Inter' || font === 'Default') return;
        const id = 'gf-' + font.replace(/\s/g, '-');
        if (!doc.getElementById(id)) {
          const link = doc.createElement('link');
          link.id = id;
          link.rel = 'stylesheet';
          link.href = `https://fonts.googleapis.com/css2?family=${font.replace(/ /g, '+')}:wght@400;500;600;700;800&display=swap`;
          doc.head.appendChild(link);
        }
      });

      return true;
    } catch {
      return false;
    }
  }

  // Structural changes need server-side rendering — save + reload
  let structuralSaveTimer = null;

  function debouncedStructuralSave() {
    if (structuralSaveTimer) clearTimeout(structuralSaveTimer);
    structuralSaveTimer = setTimeout(async () => {
      await brandSettings.save(getAllSettings());
      if (previewFrame) previewFrame.contentWindow.location.reload();
    }, 800);
  }

  // Watch CSS-variable changes — instant update (no reload)
  $effect(() => {
    accentColor; headingFont; bodyFont; headerFooterColor; siteBgColor;
    if (initialized) updatePreviewLive();
  });

  // Watch structural changes — save + reload
  $effect(() => {
    navLayout; headerStyle; headerText; bgImage; postFeedStyle;
    showFeedImages; showAuthor; showPublishDate; showPostMeta;
    enableDropCaps; showRelated;
    if (initialized) debouncedStructuralSave();
  });

  function close() {
    navigate('dashboard');
  }

  async function save() {
    try {
      await brandSettings.save(getAllSettings());
      addToast('Design saved');
      if (previewFrame) {
        try { previewFrame.contentWindow.location.reload(); } catch {}
      }
    } catch (err) {
      addToast('Failed to save', 'error');
    }
  }
</script>

<div class="design-page">
  <!-- ===== MAIN CONTENT ===== -->
  <div class="design-body">
    <!-- LIVE PREVIEW -->
    <div class="preview-area">
      <div class="preview-wrapper" class:mobile={deviceMode === 'mobile'}>
        <iframe
          class="preview-iframe"
          src={previewTab === 'post' ? '/preview/sample-post' : '/preview/'}
          title="Site preview"
          bind:this={previewFrame}
          onload={() => setTimeout(updatePreviewLive, 500)}
        ></iframe>
      </div>
    </div>

    <!-- ===== RIGHT PANEL ===== -->
    <div class="settings-panel">
      <div class="panel-tabs">
        <button class="panel-tab" class:active={panelTab === 'brand'} onclick={() => panelTab = 'brand'}>Brand</button>
        <button class="panel-tab" class:active={panelTab === 'layout'} onclick={() => panelTab = 'layout'}>Layout</button>
      </div>

      <div class="panel-body">
        {#if panelTab === 'brand'}
          <!-- BRAND TAB -->
          <div class="panel-section panel-section-row">
            <label class="field-label">Accent color</label>
            <ColorPicker bind:value={accentColor} />
          </div>

          <div class="panel-section">
            <label class="field-label">Site icon</label>
            <p class="field-desc">A square image used as your site's favicon and app icon</p>
            <button class="upload-btn">Upload</button>
          </div>

          <div class="panel-section">
            <label class="field-label">Site logo</label>
            <p class="field-desc">Your brand logo displayed in the site header</p>
            <button class="upload-btn">Upload</button>
          </div>

          <div class="panel-section">
            <label class="field-label">Publication cover</label>
            <p class="field-desc">An image displayed on your publication homepage</p>
            <div class="cover-thumb" style="background: linear-gradient(135deg, {accentColor}, {accentColor}88);"></div>
          </div>

          <hr class="panel-divider" />

          <div class="panel-section">
            <h3 class="section-heading">Typography</h3>
          </div>

          <div class="panel-section">
            <label class="field-label">Heading font</label>
            <div class="font-card">
              <span class="font-preview" style="font-family: {headingFontFamily}">Aa</span>
              <div class="font-info">
                <select class="font-select" bind:value={headingFont}>
                  <option>Default</option>
                  {#each googleFonts as font}
                    <option>{font}</option>
                  {/each}
                </select>
              </div>
            </div>
          </div>

          <div class="panel-section">
            <label class="field-label">Body font</label>
            <div class="font-card">
              <span class="font-preview" style="font-family: {bodyFontFamily}">Aa</span>
              <div class="font-info">
                <select class="font-select" bind:value={bodyFont}>
                  <option>Default</option>
                  {#each googleFonts as font}
                    <option>{font}</option>
                  {/each}
                </select>
              </div>
            </div>
          </div>

          <div class="panel-section">
            {#if showAddFont}
              <div class="add-font-form">
                <input
                  class="add-font-input"
                  type="text"
                  placeholder="Google Font name (e.g. Bebas Neue)"
                  bind:value={newFontName}
                  onkeydown={(e) => { if (e.key === 'Enter') addCustomFont(); }}
                />
                <div style="display:flex;gap:8px;margin-top:8px">
                  <button class="add-font-btn" onclick={addCustomFont}>Add</button>
                  <button class="add-font-cancel" onclick={() => { showAddFont = false; newFontName = ''; }}>Cancel</button>
                </div>
              </div>
            {:else}
              <button class="add-font-trigger" onclick={() => { showAddFont = true; }}>+ Add Google Font</button>
            {/if}
          </div>

        {:else}
          <!-- LAYOUT TAB -->
          <div class="panel-section">
            <h3 class="section-heading">Site wide</h3>
          </div>

          <div class="panel-section">
            <label class="field-label">Navigation layout</label>
            <select class="field-select" bind:value={navLayout}>
              <option value="logo-left">Logo left</option>
              <option value="logo-center">Logo center</option>
              <option value="logo-right">Logo right</option>
            </select>
          </div>

          <div class="panel-section panel-section-row">
            <label class="field-label">Site background color</label>
            <ColorPicker bind:value={siteBgColor} />
          </div>

          <div class="panel-section panel-section-row">
            <label class="field-label">Header & footer color</label>
            <ColorPicker bind:value={headerFooterColor} />
          </div>

          <hr class="panel-divider" />

          <div class="panel-section">
            <h3 class="section-heading">Homepage</h3>
          </div>

          <div class="panel-section">
            <label class="field-label">Header style</label>
            <select class="field-select" bind:value={headerStyle}>
              <option value="landing">Landing</option>
              <option value="highlight">Highlight</option>
              <option value="magazine">Magazine</option>
            </select>
          </div>

          <div class="panel-section">
            <label class="field-label">Header text</label>
            <input type="text" class="field-input" bind:value={headerText} placeholder="Enter header text..." />
          </div>

          <div class="panel-section">
            <Checkbox bind:checked={bgImage} label="Background image" />
          </div>

          <div class="panel-section">
            <label class="field-label">Post feed style</label>
            <select class="field-select" bind:value={postFeedStyle}>
              <option value="list">List</option>
              <option value="grid">Grid</option>
              <option value="cards">Cards</option>
            </select>
          </div>

          <div class="panel-section">
            <Checkbox bind:checked={showFeedImages} label="Show images in feed" />
          </div>

          <div class="panel-section">
            <Checkbox bind:checked={showAuthor} label="Show author" />
          </div>

          <div class="panel-section">
            <Checkbox bind:checked={showPublishDate} label="Show publish date" />
          </div>

          <hr class="panel-divider" />

          <div class="panel-section">
            <h3 class="section-heading">Post</h3>
          </div>

          <div class="panel-section">
            <Checkbox bind:checked={showPostMeta} label="Show post metadata" />
          </div>

          <div class="panel-section">
            <Checkbox bind:checked={enableDropCaps} label="Enable drop caps" />
          </div>

          <div class="panel-section">
            <Checkbox bind:checked={showRelated} label="Show related articles" />
          </div>
        {/if}
      </div>
    </div>
  </div>

  <!-- ===== ICON RAIL (right) ===== -->
  <div class="design-rail">
    <div class="rail-group">
      <button class="rail-btn" class:active={previewTab === 'homepage'} onclick={() => previewTab = 'homepage'} title="Homepage">
        <Home size={20} />
      </button>
      <button class="rail-btn" class:active={previewTab === 'post'} onclick={() => previewTab = 'post'} title="Post">
        <FileTextIcon size={20} />
      </button>
    </div>

    <div class="rail-divider"></div>

    <div class="rail-group">
      <button class="device-toggle {deviceAnim}" class:mobile={deviceMode === 'mobile'} onclick={toggleDevice} title={deviceMode === 'desktop' ? 'Switch to mobile' : 'Switch to desktop'}>
        <span class="device-toggle-track">
          <span class="device-toggle-icon" style="color: {deviceMode === 'desktop' ? '#fff' : '#505460'}">
            <Monitor size={14} strokeWidth={2} />
          </span>
          <span class="device-toggle-icon" style="color: {deviceMode === 'mobile' ? '#fff' : '#505460'}">
            <Smartphone size={14} strokeWidth={2} />
          </span>
          <span class="device-toggle-knob"></span>
        </span>
      </button>
    </div>

    <div class="rail-spacer"></div>

    <div class="rail-divider"></div>

    <div class="rail-group">
      <button class="rail-btn rail-save" onclick={save} title="Save">
        <Save size={18} />
      </button>
      <button class="rail-btn rail-close" onclick={close} title="Close">
        <X size={18} />
      </button>
    </div>
  </div>
</div>

<style>
  /* ===== FULL-SCREEN LAYOUT ===== */
  .design-page {
    position: fixed;
    inset: 0;
    z-index: 200;
    display: flex;
    flex-direction: row;
    background: var(--bg);
    color: var(--text);
  }

  /* ===== ICON RAIL ===== */
  .design-rail {
    width: 52px;
    min-width: 52px;
    background: var(--bg);
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 16px 0;
    border-left: 1px solid var(--border);
  }
  .rail-group {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 4px;
  }
  .rail-btn {
    width: 36px;
    height: 36px;
    border: none;
    background: transparent;
    color: var(--dim);
    border-radius: 8px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all .15s;
    position: relative;
  }
  .rail-btn:hover {
    color: var(--sec);
    background: var(--hover);
  }
  .rail-btn.active {
    color: var(--text);
  }
  .rail-btn.active {
    background: var(--purple-bg);
  }
  .rail-btn.active::before {
    content: '';
    position: absolute;
    right: 0;
    top: 50%;
    transform: translateY(-50%);
    width: 3px;
    height: 24px;
    background: var(--purple);
    border-radius: 3px 0 0 3px;
  }
  .rail-divider {
    width: 28px;
    height: 1px;
    background: var(--border);
    margin: 14px 0;
  }
  .rail-spacer {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    position: relative;
  }
  .device-toggle {
    background: none;
    border: none;
    cursor: pointer;
    padding: 0;
  }
  .device-toggle-track {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: space-between;
    width: 30px;
    height: 56px;
    border-radius: 15px;
    background: var(--hover);
    border: 1px solid var(--border);
    position: relative;
    padding: 5px 0;
  }
  .device-toggle-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 3;
    position: relative;
    transition: color .2s;
    width: 20px;
    height: 20px;
  }
  .device-toggle-knob {
    position: absolute;
    top: 2px;
    left: 50%;
    transform: translateX(-50%);
    width: 24px;
    height: 24px;
    border-radius: 14px;
    background: var(--purple);
    z-index: 1;
  }
  .device-toggle:not(.to-down):not(.to-up) .device-toggle-knob {
    transition: top .3s cubic-bezier(.4, 0, .2, 1);
  }
  .device-toggle.mobile .device-toggle-knob {
    top: 28px;
  }
  .device-toggle.to-down .device-toggle-knob {
    animation: goo-down .4s cubic-bezier(.4, 0, .2, 1) forwards;
  }
  .device-toggle.to-up .device-toggle-knob {
    animation: goo-up .4s cubic-bezier(.4, 0, .2, 1) forwards;
  }
  @keyframes goo-down {
    0%   { top: 2px; height: 24px; }
    30%  { top: 2px; height: 44px; }
    60%  { top: 18px; height: 34px; }
    80%  { top: 28px; height: 26px; }
    100% { top: 28px; height: 24px; }
  }
  @keyframes goo-up {
    0%   { top: 28px; height: 24px; }
    30%  { top: 8px; height: 44px; }
    60%  { top: 2px; height: 34px; }
    80%  { top: 2px; height: 26px; }
    100% { top: 2px; height: 24px; }
  }
  .device-toggle:not(.mobile) .device-toggle-icon:first-child {
    color: #fff;
  }
  .device-toggle.mobile .device-toggle-icon:last-child {
    color: #fff;
  }
  .rail-save:hover {
    color: var(--green);
  }
  .rail-close:hover {
    color: var(--red);
  }

  /* ===== BODY LAYOUT ===== */
  .design-body {
    flex: 1;
    display: flex;
    overflow: hidden;
  }

  /* ===== PREVIEW AREA ===== */
  .preview-area {
    flex: 1;
    overflow: hidden;
    display: flex;
    justify-content: center;
    align-items: stretch;
    background: var(--hover);
  }
  :global(.dark) .preview-area {
    background: #0a0a0e;
  }
  .preview-wrapper {
    width: 100%;
    height: 100%;
    transition: all .3s ease;
    overflow: hidden;
  }
  .preview-wrapper.mobile {
    width: 375px;
    height: auto;
    margin: 24px auto;
    border-radius: 32px;
    box-shadow: 0 0 0 12px #222, 0 0 0 14px #444;
    overflow: hidden;
  }
  .preview-iframe {
    width: 100%;
    height: 100%;
    border: none;
    display: block;
    background: #fff;
  }
  .preview-wrapper.mobile .preview-iframe {
    border-radius: 0;
  }

  /* ===== SETTINGS PANEL ===== */
  .settings-panel {
    width: 360px;
    min-width: 360px;
    border-left: 1px solid var(--border);
    background: var(--bg);
    display: flex;
    flex-direction: column;
    overflow: hidden;
  }
  .panel-tabs {
    display: flex;
    border-bottom: 1px solid var(--border);
    padding: 0 20px;
  }
  .panel-tab {
    background: none;
    border: none;
    border-bottom: 2px solid transparent;
    font-size: 14px;
    font-weight: 600;
    color: var(--dim);
    padding: 14px 16px;
    cursor: pointer;
    transition: color .15s, border-color .15s;
    font-family: var(--font);
  }
  .panel-tab:hover { color: var(--sec); }
  .panel-tab.active {
    color: var(--text);
    border-bottom-color: var(--purple);
  }
  .panel-body {
    flex: 1;
    overflow-y: auto;
    padding: 24px 20px;
  }
  .panel-section {
    margin-bottom: 20px;
  }
  .panel-section:has(.toggle-wrap),
  .panel-section:has(:global(.kt)) {
    display: flex;
    align-items: center;
    justify-content: space-between;
  }
  .section-heading {
    font-size: 13px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .04em;
    color: var(--text);
    margin: 0 0 4px;
  }
  .field-label {
    display: block;
    font-size: 14px;
    font-weight: 600;
    color: var(--text);
    margin-bottom: 6px;
  }
  .panel-section:has(.toggle-wrap) .field-label,
  .panel-section:has(:global(.kt)) .field-label {
    margin-bottom: 0;
  }
  .field-desc {
    font-size: 13px;
    color: var(--dim);
    margin: 0 0 10px;
    line-height: 1.4;
  }
  .panel-divider {
    border: none;
    border-top: 1px solid var(--border);
    margin: 24px 0;
  }

  /* Color field */
  .color-field {
    display: flex;
    align-items: center;
    gap: 10px;
  }
  .color-bubble-wrap {
    position: relative;
    cursor: pointer;
    flex-shrink: 0;
  }
  .color-input-hidden {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    opacity: 0;
    cursor: pointer;
    pointer-events: auto;
  }
  .color-bubble {
    display: block;
    width: 32px;
    height: 32px;
    position: relative;
    transition: transform .2s, filter .2s;
  }
  .color-bubble svg.cb-shape {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
  }
  .color-bubble-fill {
    transition: transform .3s cubic-bezier(.34, 1.56, .64, 1);
    transform: scale(1);
    transform-origin: center;
  }
  .color-bubble-wrap:hover .color-bubble {
    transform: scale(1.12);
  }
  .color-bubble-wrap:active .color-bubble {
    transform: scale(0.92);
    transition: transform .1s ease;
  }
  .color-value {
    font-size: 13px;
    color: var(--sec);
    font-family: monospace;
  }

  /* Upload btn */
  .upload-btn {
    background: var(--hover);
    border: 1px solid var(--border);
    color: var(--text);
    font-size: 13px;
    font-weight: 500;
    padding: 8px 16px;
    border-radius: 8px;
    cursor: pointer;
    transition: all .15s;
  }
  .upload-btn:hover {
    background: var(--raised, var(--hover));
  }

  /* Cover thumbnail */
  .cover-thumb {
    width: 100%;
    height: 80px;
    border-radius: 8px;
    margin-top: 4px;
  }

  /* Font card */
  .font-card {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 12px 14px;
    background: var(--hover);
    border: 1px solid var(--border);
    border-radius: 8px;
  }
  .font-preview {
    font-size: 28px;
    font-weight: 700;
    color: var(--text);
    line-height: 1;
    min-width: 36px;
    text-align: center;
  }
  .font-info { flex: 1; }
  .font-select {
    width: 100%;
    background: var(--bg);
    border: 1px solid var(--border);
    color: var(--text);
    font-size: 13px;
    padding: 8px 16px;
    padding-right: 40px;
    border-radius: 6px;
    cursor: pointer;
    appearance: none;
    font-family: var(--font);
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23878B95' stroke-width='2'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 16px center;
  }
  .add-font-trigger {
    width: 100%;
    padding: 10px;
    border: 1px dashed var(--border);
    border-radius: 8px;
    background: transparent;
    color: var(--dim);
    font-size: 13px;
    font-weight: 500;
    font-family: var(--font);
    cursor: pointer;
    transition: all .15s;
  }
  .add-font-trigger:hover {
    border-color: var(--purple);
    color: var(--purple-soft);
  }
  .add-font-input {
    width: 100%;
    padding: 10px 14px;
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: 6px;
    color: var(--text);
    font-size: 14px;
    font-family: var(--font);
    outline: none;
  }
  .add-font-input:focus {
    border-color: var(--purple);
  }
  .add-font-btn {
    padding: 6px 16px;
    background: var(--purple);
    border: none;
    border-radius: 6px;
    color: #fff;
    font-size: 13px;
    font-weight: 500;
    font-family: var(--font);
    cursor: pointer;
  }
  .add-font-cancel {
    padding: 6px 16px;
    background: transparent;
    border: 1px solid var(--border);
    border-radius: 6px;
    color: var(--sec);
    font-size: 13px;
    font-family: var(--font);
    cursor: pointer;
  }

  /* Select / input fields */
  .field-select {
    width: 100%;
    height: 48px;
    background: var(--bg);
    border: 1px solid var(--border);
    color: var(--text);
    font-size: 14px;
    padding: 0 16px;
    padding-right: 40px;
    border-radius: 8px;
    cursor: pointer;
    appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23878B95' stroke-width='2'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 16px center;
  }
  .panel-section-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
  }
  .panel-section-row .field-label {
    margin-bottom: 0;
  }
  .field-input {
    width: 100%;
    height: 48px;
    background: var(--bg);
    border: 1px solid var(--border);
    color: var(--text);
    font-size: 14px;
    padding: 0 12px;
    border-radius: 8px;
    box-sizing: border-box;
  }
  .field-input:focus, .field-select:focus {
    outline: none;
    border-color: var(--purple);
    box-shadow: 0 0 0 3px rgba(108, 60, 225, .12);
  }

  /* Toggle switch */
  .toggle-wrap {
    position: relative;
    cursor: pointer;
    display: inline-flex;
    flex-shrink: 0;
  }
  .toggle-checkbox {
    position: absolute;
    opacity: 0;
    width: 0;
    height: 0;
  }
  .toggle-track {
    width: 44px;
    height: 24px;
    background: rgba(255,255,255,.08);
    border-radius: 12px;
    position: relative;
    transition: background .2s;
    overflow: hidden;
  }
  .toggle-checkbox:checked + .toggle-track {
    background: var(--purple);
  }
  .toggle-goo {
    position: absolute;
    inset: 2px;
    filter: url(#goo);
  }
  .toggle-knob {
    position: absolute;
    top: 0;
    left: 0;
    width: 20px;
    height: 20px;
    background: #fff;
    border-radius: 50%;
    transition: left .45s cubic-bezier(.4, 0, .2, 1);
  }
  .toggle-trail {
    position: absolute;
    top: 3px;
    left: 3px;
    width: 14px;
    height: 14px;
    background: #fff;
    border-radius: 50%;
    transition: left .6s cubic-bezier(.4, 0, .2, 1);
  }
  .toggle-checkbox:checked + .toggle-track .toggle-knob {
    left: 20px;
  }
  .toggle-checkbox:checked + .toggle-track .toggle-trail {
    left: 23px;
  }

</style>
