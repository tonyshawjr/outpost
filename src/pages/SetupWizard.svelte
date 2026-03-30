<script>
  import { onMount } from 'svelte';
  import { setup as setupApi, forge as forgeApi, site as siteApi } from '$lib/api.js';
  import { setupCompleted, navigate, addToast } from '$lib/stores.js';

  let step = $state(1);
  let siteName = $state('');
  let detected = $state(false);
  let detecting = $state(true);
  let applying = $state(false);

  // Forge analysis state
  let analyzing = $state(false);
  let analysis = $state(null);
  let forgeApplying = $state(false);
  let forgeResult = $state(null);

  // Pages expand/collapse
  let pagesExpanded = $state(false);
  const PAGE_PREVIEW_COUNT = 5;

  // Step 3 animation
  let showComplete = $state(false);
  let showSummary = $state(false);
  let showButton = $state(false);

  // Derived stats from analysis
  let stats = $derived(() => {
    if (!analysis) return null;
    return {
      pages: analysis.pages?.length || 0,
      fields: analysis.pages?.reduce((sum, p) => sum + (p.fields?.length || 0), 0) || 0,
      partials: analysis.partials?.length || 0,
      menus: analysis.menus?.length || 0,
      globals: analysis.globals?.length || 0,
    };
  });

  let visiblePages = $derived(() => {
    if (!analysis?.pages) return [];
    if (pagesExpanded) return analysis.pages;
    return analysis.pages.slice(0, PAGE_PREVIEW_COUNT);
  });

  let hiddenPageCount = $derived(() => {
    if (!analysis?.pages) return 0;
    return Math.max(0, analysis.pages.length - PAGE_PREVIEW_COUNT);
  });

  // Auto-detect site name on mount
  onMount(async () => {
    try {
      const data = await siteApi.detect();
      if (data.site_name) {
        siteName = data.site_name;
        detected = true;
      }
    } catch (e) {
      // Detection failed silently
    } finally {
      detecting = false;
    }
  });

  function handleKeydown(e) {
    if (e.key === 'Enter' && siteName.trim()) nextStep();
  }

  async function nextStep() {
    if (step === 1 && !applying) {
      await applySetup();
    }
  }

  async function applySetup() {
    applying = true;
    try {
      await setupApi.apply({
        site_name: siteName.trim(),
      });
      setupCompleted.set(true);
      step = 2;
      runForgeAnalysis();
    } catch (e) {
      addToast(e.message, 'error');
    } finally {
      applying = false;
    }
  }

  async function runForgeAnalysis() {
    analyzing = true;
    try {
      const data = await forgeApi.analyze();
      analysis = data;
    } catch (e) {
      analysis = null;
    } finally {
      analyzing = false;
    }
  }

  async function applyForge() {
    forgeApplying = true;
    try {
      const data = await forgeApi.analyzeApply({ backup: true });
      forgeResult = data;
      step = 3;
      // Stagger the completion animations
      setTimeout(() => { showComplete = true; }, 100);
      setTimeout(() => { showSummary = true; }, 500);
      setTimeout(() => { showButton = true; }, 800);
    } catch (e) {
      addToast('Forge error: ' + e.message, 'error');
    } finally {
      forgeApplying = false;
    }
  }

  function skipForge() {
    step = 3;
    setTimeout(() => { showComplete = true; }, 100);
    setTimeout(() => { showSummary = true; }, 500);
    setTimeout(() => { showButton = true; }, 800);
  }

  async function skip() {
    try {
      await setupApi.apply({ skip: true });
      setupCompleted.set(true);
      navigate('dashboard');
    } catch (e) {
      setupCompleted.set(true);
      navigate('dashboard');
    }
  }

  function finish() {
    // Go to the live site so user sees their site with Outpost active
    window.location.href = '/';
  }

  function formatPageName(file) {
    if (!file) return 'Page';
    // Handle full server paths — extract just the filename
    const basename = file.split('/').pop();
    const name = basename.replace(/\.html$/, '').replace(/[_-]/g, ' ');
    if (name === 'index') return 'Home';
    return name.split(' ').map(w => w.charAt(0).toUpperCase() + w.slice(1)).join(' ');
  }

  function formatSectionName(section) {
    if (!section) return '';
    // Strip all variants: "section:hero", "/section:hero", "Section:Hero"
    let clean = section.replace(/^\/?(s|S)ection:/i, '').replace(/^\//, '');
    // Convert kebab-case / snake_case to title case
    return clean.replace(/[-_]/g, ' ').replace(/\b\w/g, c => c.toUpperCase()).trim();
  }

  function formatPartialName(partial) {
    const name = partial.name || partial.file || '';
    return name.replace(/[-_]/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
  }
</script>

<div class="wizard-shell">
  <div class="wizard-container" class:wizard-wide={step === 2 && !analyzing && analysis}>

    <!-- Skip setup — always accessible but unobtrusive -->
    {#if step < 3}
      <button class="wizard-skip" onclick={skip}>Skip setup</button>
    {/if}

    <!-- Minimal step indicator -->
    {#if step < 3}
      <div class="wizard-steps">
        <span class="step-indicator" class:active={step === 1} class:done={step > 1}>1</span>
        <span class="step-line" class:filled={step > 1}></span>
        <span class="step-indicator" class:active={step === 2} class:done={step > 2}>2</span>
        <span class="step-line" class:filled={step > 2}></span>
        <span class="step-indicator" class:active={step === 3}>3</span>
      </div>
    {/if}

    <div class="wizard-body">

      <!-- ============================================ -->
      <!-- STEP 1: Site Name                            -->
      <!-- ============================================ -->
      {#if step === 1}
        {#if applying}
          <div class="wizard-center">
            <div class="spinner-ring"></div>
            <p class="loading-label">Setting up your site...</p>
          </div>
        {:else}
          <div class="wizard-center">
            <h1 class="hero-heading">Welcome to Outpost</h1>
            <p class="hero-sub">Let's set up your site in under a minute.</p>

            <div class="name-field-area">
              {#if detecting}
                <div class="detecting">
                  <div class="detecting-pulse"></div>
                  <span>Detecting from your site...</span>
                </div>
              {:else}
                <label class="name-label" for="site-name-input">Site name</label>
                <div class="name-input-wrap">
                  <input
                    id="site-name-input"
                    class="name-input"
                    type="text"
                    placeholder="My Website"
                    bind:value={siteName}
                    onkeydown={handleKeydown}
                    autofocus
                    maxlength="200"
                  />
                  {#if detected && siteName}
                    <span class="auto-badge">Auto-detected</span>
                  {/if}
                </div>
              {/if}
            </div>

            <button
              class="btn-primary"
              onclick={nextStep}
              disabled={!siteName.trim() || detecting}
            >
              Continue
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                <path d="M5 12h14M12 5l7 7-7 7"/>
              </svg>
            </button>
          </div>
        {/if}


      <!-- ============================================ -->
      <!-- STEP 2: Forge Preview                        -->
      <!-- ============================================ -->
      {:else if step === 2}
        <div class="forge-step">
          {#if analyzing}
            <div class="wizard-center">
              <div class="scan-anim">
                <div class="scan-ring-outer"></div>
                <div class="scan-ring-inner"></div>
                <svg class="scan-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="22" height="22">
                  <circle cx="11" cy="11" r="8"/>
                  <path d="M21 21l-4.35-4.35"/>
                </svg>
              </div>
              <h2 class="scan-heading">Scanning your site</h2>
              <p class="scan-sub">Analyzing templates, fields, and navigation...</p>
              <div class="scan-track">
                <div class="scan-fill"></div>
              </div>
            </div>

          {:else if analysis && stats()}
            <div class="forge-results">
              <!-- Header -->
              <div class="forge-header">
                <h2 class="forge-heading">Your site at a glance</h2>
                <p class="forge-sub">Here is what Forge found. Review the details, then apply to make everything editable.</p>
              </div>

              <!-- Hero stats — just 3 big numbers -->
              <div class="hero-stats">
                <div class="hero-stat">
                  <span class="hero-stat-num">{stats().pages}</span>
                  <span class="hero-stat-label">{stats().pages === 1 ? 'Page' : 'Pages'}</span>
                </div>
                <div class="hero-stat-divider"></div>
                <div class="hero-stat">
                  <span class="hero-stat-num">{stats().fields}</span>
                  <span class="hero-stat-label">{stats().fields === 1 ? 'Field' : 'Fields'}</span>
                </div>
                <div class="hero-stat-divider"></div>
                <div class="hero-stat">
                  <span class="hero-stat-num">{stats().menus}</span>
                  <span class="hero-stat-label">{stats().menus === 1 ? 'Menu' : 'Menus'}</span>
                </div>
              </div>

              <!-- Pages -->
              {#if analysis.pages?.length}
                <div class="section">
                  <div class="section-head">
                    <span class="section-label">Pages</span>
                    <span class="section-count">{analysis.pages.length}</span>
                  </div>
                  <div class="card-list">
                    {#each visiblePages() as page, i}
                      <div class="page-card" style="animation-delay: {i * 40}ms">
                        <div class="page-card-main">
                          <div class="page-card-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="15" height="15">
                              <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                              <polyline points="14 2 14 8 20 8"/>
                            </svg>
                          </div>
                          <span class="page-card-name">{page.title || formatPageName(page.filename || page.file)}</span>
                          <div class="page-card-meta">
                            {#if page.sections?.length}
                              <span>{page.sections.length} {page.sections.length === 1 ? 'section' : 'sections'}</span>
                            {/if}
                            <span>{page.fields?.length || 0} fields</span>
                          </div>
                        </div>
                        {#if page.sections?.length}
                          <div class="page-card-sections">
                            {#each page.sections as section}
                              <span class="section-chip">{formatSectionName(section)}</span>
                            {/each}
                          </div>
                        {/if}
                      </div>
                    {/each}
                    {#if hiddenPageCount() > 0 && !pagesExpanded}
                      <button class="show-more" onclick={() => pagesExpanded = true}>
                        Show {hiddenPageCount()} more {hiddenPageCount() === 1 ? 'page' : 'pages'}
                      </button>
                    {/if}
                  </div>
                </div>
              {/if}

              <!-- Shared Templates -->
              {#if analysis.partials?.length}
                <div class="section">
                  <div class="section-head">
                    <span class="section-label">Shared Templates</span>
                    <span class="section-count">{analysis.partials.length}</span>
                  </div>
                  <p class="section-hint">These shared elements will be extracted into reusable partials.</p>
                  <div class="simple-list">
                    {#each analysis.partials as partial}
                      <div class="simple-row">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="14" height="14">
                          <rect x="3" y="3" width="18" height="18" rx="2"/>
                          <path d="M3 9h18"/>
                        </svg>
                        <span class="simple-name">{formatPartialName(partial)}</span>
                      </div>
                    {/each}
                  </div>
                </div>
              {/if}

              <!-- Navigation -->
              {#if analysis.menus?.length}
                <div class="section">
                  <div class="section-head">
                    <span class="section-label">Navigation</span>
                    <span class="section-count">{analysis.menus.length}</span>
                  </div>
                  <div class="simple-list">
                    {#each analysis.menus as menu}
                      <div class="nav-row">
                        <div class="nav-row-top">
                          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="14" height="14">
                            <line x1="3" y1="6" x2="21" y2="6"/>
                            <line x1="3" y1="12" x2="21" y2="12"/>
                            <line x1="3" y1="18" x2="21" y2="18"/>
                          </svg>
                          <span class="simple-name">{menu.name}</span>
                          <span class="nav-item-count">{menu.items?.length ?? 0} items</span>
                        </div>
                        {#if menu.items?.length}
                          <div class="nav-labels">
                            {#each menu.items as item}
                              <span class="nav-label">{item.label || 'Link'}</span>
                            {/each}
                          </div>
                        {/if}
                      </div>
                    {/each}
                  </div>
                </div>
              {/if}

              <!-- Globals -->
              {#if analysis.globals?.length}
                <div class="section">
                  <div class="section-head">
                    <span class="section-label">Global Fields</span>
                    <span class="section-count">{analysis.globals.length}</span>
                  </div>
                  <div class="globals-grid">
                    {#each analysis.globals as g}
                      <div class="global-item">
                        <span class="global-name">{g.name || g.field}</span>
                        {#if g.type}
                          <span class="global-type">{g.type}</span>
                        {/if}
                      </div>
                    {/each}
                  </div>
                </div>
              {/if}

              <!-- Warnings -->
              {#if analysis.warnings?.length}
                <div class="section">
                  <div class="section-head">
                    <span class="section-label" style="color: #d4a017;">Warnings</span>
                  </div>
                  <div class="simple-list">
                    {#each analysis.warnings as warning}
                      <div class="warning-row">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#d4a017" stroke-width="2">
                          <path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
                          <line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
                        </svg>
                        <span>{warning}</span>
                      </div>
                    {/each}
                  </div>
                </div>
              {/if}

              <!-- Actions -->
              <div class="forge-actions">
                <button class="btn-primary btn-large" onclick={applyForge} disabled={forgeApplying}>
                  {#if forgeApplying}
                    <div class="btn-spinner"></div>
                    Applying Forge...
                  {:else}
                    Apply Forge
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                      <path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/>
                    </svg>
                  {/if}
                </button>
                <button class="btn-ghost" onclick={skipForge}>Skip for now</button>
              </div>
            </div>

          {:else}
            <!-- No HTML files found -->
            <div class="wizard-center">
              <div class="empty-circle">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="28" height="28">
                  <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                  <polyline points="14 2 14 8 20 8"/>
                  <line x1="9" y1="15" x2="15" y2="15"/>
                </svg>
              </div>
              <h2 class="scan-heading">No HTML templates found</h2>
              <p class="scan-sub">Your site doesn't have HTML files yet, or they're already configured for Outpost.</p>
              <button class="btn-primary" onclick={skipForge}>Continue to Dashboard</button>
            </div>
          {/if}
        </div>


      <!-- ============================================ -->
      <!-- STEP 3: Complete                             -->
      <!-- ============================================ -->
      {:else if step === 3}
        <div class="wizard-center">
          <div class="complete-check" class:visible={showComplete}>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="32" height="32">
              <polyline points="20 6 9 17 4 12"/>
            </svg>
          </div>

          <h1 class="hero-heading" class:visible={showComplete} style="transition-delay: 0.1s">
            Your site is ready
          </h1>

          <div class="complete-summary" class:visible={showSummary}>
            {#if forgeResult}
              <div class="summary-stats">
                {#if forgeResult.created_pages}
                  <div class="summary-item">
                    <span class="summary-num">{forgeResult.created_pages}</span>
                    <span class="summary-text">{forgeResult.created_pages === 1 ? 'page' : 'pages'} created</span>
                  </div>
                {/if}
                {#if forgeResult.created_fields}
                  <div class="summary-item">
                    <span class="summary-num">{forgeResult.created_fields}</span>
                    <span class="summary-text">{forgeResult.created_fields === 1 ? 'field' : 'fields'} registered</span>
                  </div>
                {/if}
                {#if forgeResult.created_menus}
                  <div class="summary-item">
                    <span class="summary-num">{forgeResult.created_menus}</span>
                    <span class="summary-text">{forgeResult.created_menus === 1 ? 'menu' : 'menus'} imported</span>
                  </div>
                {/if}
              </div>
            {:else}
              <p class="complete-desc">Your site is ready. Go see it live.</p>
            {/if}
          </div>

          <div class="complete-action" class:visible={showButton}>
            <button class="btn-primary btn-large" onclick={finish}>
              View Your Site
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                <path d="M5 12h14M12 5l7 7-7 7"/>
              </svg>
            </button>
          </div>
        </div>
      {/if}
    </div>
  </div>
</div>

<style>
  /* ========================================
     Shell & Container
     ======================================== */
  .wizard-shell {
    position: fixed;
    inset: 0;
    background: #0a0a0a;
    display: flex;
    align-items: flex-start;
    justify-content: center;
    z-index: 9999;
    overflow-y: auto;
    padding: 60px 24px 80px;
  }

  .wizard-container {
    width: 100%;
    max-width: 560px;
    position: relative;
    transition: max-width 0.4s cubic-bezier(0.16, 1, 0.3, 1);
  }

  .wizard-container.wizard-wide {
    max-width: 640px;
  }

  /* ========================================
     Skip Button
     ======================================== */
  .wizard-skip {
    position: absolute;
    top: -40px;
    right: 0;
    background: none;
    border: none;
    color: rgba(255, 255, 255, 0.2);
    font-size: 13px;
    cursor: pointer;
    padding: 4px 0;
    transition: color 0.15s;
    font-family: inherit;
  }
  .wizard-skip:hover {
    color: rgba(255, 255, 255, 0.45);
  }

  /* ========================================
     Step Indicators
     ======================================== */
  .wizard-steps {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0;
    margin-bottom: 56px;
  }

  .step-indicator {
    width: 24px;
    height: 24px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 11px;
    font-weight: 600;
    color: rgba(255, 255, 255, 0.2);
    border: 1px solid rgba(255, 255, 255, 0.08);
    transition: all 0.3s ease;
    font-family: inherit;
  }

  .step-indicator.active {
    color: #fff;
    border-color: rgba(255, 255, 255, 0.3);
    background: rgba(255, 255, 255, 0.06);
  }

  .step-indicator.done {
    color: #fff;
    border-color: #2D5A47;
    background: #2D5A47;
  }

  .step-line {
    width: 40px;
    height: 1px;
    background: rgba(255, 255, 255, 0.06);
    transition: background 0.3s ease;
  }

  .step-line.filled {
    background: #2D5A47;
  }

  /* ========================================
     Wizard Body
     ======================================== */
  .wizard-body {
    min-height: 360px;
    display: flex;
    align-items: center;
    justify-content: center;
  }

  /* ========================================
     Centered layout helper
     ======================================== */
  .wizard-center {
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    width: 100%;
  }

  /* ========================================
     Typography
     ======================================== */
  .hero-heading {
    font-family: var(--font-serif, Georgia, 'Times New Roman', serif);
    font-size: 32px;
    font-weight: 600;
    color: #fff;
    margin: 0 0 12px;
    letter-spacing: -0.6px;
    line-height: 1.2;
    opacity: 1;
    transform: translateY(0);
    transition: opacity 0.5s ease, transform 0.5s ease;
  }

  .hero-heading:not(.visible) {
    /* used in step 3 for stagger */
  }

  .hero-sub {
    font-size: 16px;
    color: rgba(255, 255, 255, 0.35);
    margin: 0 0 40px;
    line-height: 1.5;
  }

  /* ========================================
     Step 1: Name Input
     ======================================== */
  .name-field-area {
    width: 100%;
    max-width: 400px;
    margin-bottom: 32px;
    min-height: 80px;
  }

  .name-label {
    display: block;
    font-size: 11px;
    font-weight: 600;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: rgba(255, 255, 255, 0.3);
    margin-bottom: 8px;
    text-align: left;
  }

  .name-input-wrap {
    position: relative;
  }

  .name-input {
    width: 100%;
    padding: 16px 0;
    font-size: 22px;
    font-family: var(--font-serif, Georgia, 'Times New Roman', serif);
    background: transparent;
    border: none;
    border-bottom: 1px solid rgba(255, 255, 255, 0.12);
    color: #fff;
    outline: none;
    transition: border-color 0.2s;
    letter-spacing: -0.3px;
    box-sizing: border-box;
  }

  .name-input::placeholder {
    color: rgba(255, 255, 255, 0.15);
  }

  .name-input:focus {
    border-bottom-color: rgba(255, 255, 255, 0.35);
  }

  .auto-badge {
    position: absolute;
    right: 0;
    top: 50%;
    transform: translateY(-50%);
    font-size: 11px;
    font-weight: 500;
    color: #4a9e7a;
    background: rgba(45, 90, 71, 0.15);
    padding: 3px 8px;
    border-radius: 4px;
    letter-spacing: 0.02em;
  }

  .detecting {
    display: flex;
    align-items: center;
    gap: 10px;
    color: rgba(255, 255, 255, 0.3);
    font-size: 14px;
    padding: 20px 0;
  }

  .detecting-pulse {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: #4a9e7a;
    animation: pulse-glow 1.4s ease-in-out infinite;
  }

  @keyframes pulse-glow {
    0%, 100% { opacity: 0.3; transform: scale(0.8); }
    50% { opacity: 1; transform: scale(1.3); }
  }

  /* ========================================
     Buttons
     ======================================== */
  .btn-primary {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    background: #2D5A47;
    color: #fff;
    border: none;
    border-radius: 10px;
    font-size: 15px;
    font-weight: 600;
    font-family: inherit;
    padding: 12px 32px;
    cursor: pointer;
    transition: background 0.15s, transform 0.1s;
  }

  .btn-primary:hover {
    background: #245040;
  }

  .btn-primary:active {
    transform: scale(0.98);
  }

  .btn-primary:disabled {
    background: rgba(255, 255, 255, 0.06);
    color: rgba(255, 255, 255, 0.2);
    cursor: not-allowed;
  }

  .btn-primary:disabled:hover {
    background: rgba(255, 255, 255, 0.06);
  }

  .btn-large {
    padding: 14px 48px;
    font-size: 16px;
    width: 100%;
    max-width: 340px;
  }

  .btn-ghost {
    background: none;
    border: none;
    color: rgba(255, 255, 255, 0.25);
    font-size: 13px;
    font-weight: 500;
    font-family: inherit;
    cursor: pointer;
    padding: 8px 16px;
    transition: color 0.15s;
  }

  .btn-ghost:hover {
    color: rgba(255, 255, 255, 0.5);
  }

  .btn-spinner {
    width: 16px;
    height: 16px;
    border: 2px solid rgba(255, 255, 255, 0.2);
    border-top-color: #fff;
    border-radius: 50%;
    animation: spin 0.6s linear infinite;
  }

  /* ========================================
     Loading States
     ======================================== */
  .spinner-ring {
    width: 28px;
    height: 28px;
    border: 2px solid rgba(255, 255, 255, 0.08);
    border-top-color: rgba(255, 255, 255, 0.5);
    border-radius: 50%;
    animation: spin 0.7s linear infinite;
    margin-bottom: 20px;
  }

  .loading-label {
    font-size: 15px;
    color: rgba(255, 255, 255, 0.35);
    margin: 0;
  }

  @keyframes spin {
    to { transform: rotate(360deg); }
  }

  /* ========================================
     Step 2: Scanning Animation
     ======================================== */
  .forge-step {
    width: 100%;
  }

  .scan-anim {
    position: relative;
    width: 56px;
    height: 56px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 24px;
  }

  .scan-ring-outer {
    position: absolute;
    inset: 0;
    border: 1.5px solid rgba(74, 158, 122, 0.1);
    border-top-color: rgba(74, 158, 122, 0.6);
    border-radius: 50%;
    animation: spin 1.2s linear infinite;
  }

  .scan-ring-inner {
    position: absolute;
    inset: 6px;
    border: 1.5px solid rgba(74, 158, 122, 0.06);
    border-bottom-color: rgba(74, 158, 122, 0.3);
    border-radius: 50%;
    animation: spin 1.8s linear infinite reverse;
  }

  .scan-icon {
    color: rgba(255, 255, 255, 0.5);
    position: relative;
    z-index: 1;
  }

  .scan-heading {
    font-family: var(--font-serif, Georgia, 'Times New Roman', serif);
    font-size: 22px;
    font-weight: 600;
    color: #fff;
    margin: 0 0 8px;
    letter-spacing: -0.3px;
  }

  .scan-sub {
    font-size: 14px;
    color: rgba(255, 255, 255, 0.3);
    margin: 0 0 28px;
    line-height: 1.5;
  }

  .scan-track {
    width: 180px;
    height: 2px;
    background: rgba(255, 255, 255, 0.06);
    border-radius: 2px;
    overflow: hidden;
  }

  .scan-fill {
    height: 100%;
    background: linear-gradient(90deg, #2D5A47, #4a9e7a);
    border-radius: 2px;
    animation: scan-sweep 2s ease-in-out infinite;
  }

  @keyframes scan-sweep {
    0% { width: 0%; margin-left: 0; }
    50% { width: 60%; margin-left: 20%; }
    100% { width: 0%; margin-left: 100%; }
  }

  /* ========================================
     Step 2: Forge Results
     ======================================== */
  .forge-results {
    width: 100%;
    animation: fade-up 0.4s ease;
  }

  @keyframes fade-up {
    from { opacity: 0; transform: translateY(12px); }
    to { opacity: 1; transform: translateY(0); }
  }

  .forge-header {
    text-align: center;
    margin-bottom: 36px;
  }

  .forge-heading {
    font-family: var(--font-serif, Georgia, 'Times New Roman', serif);
    font-size: 26px;
    font-weight: 600;
    color: #fff;
    margin: 0 0 8px;
    letter-spacing: -0.4px;
  }

  .forge-sub {
    font-size: 14px;
    color: rgba(255, 255, 255, 0.35);
    margin: 0;
    line-height: 1.5;
  }

  /* Hero Stats */
  .hero-stats {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0;
    padding: 28px 0;
    margin-bottom: 36px;
    border-top: 1px solid rgba(255, 255, 255, 0.06);
    border-bottom: 1px solid rgba(255, 255, 255, 0.06);
  }

  .hero-stat {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 4px;
  }

  .hero-stat-num {
    font-size: 36px;
    font-weight: 700;
    color: #fff;
    letter-spacing: -1px;
    line-height: 1;
    font-variant-numeric: tabular-nums;
  }

  .hero-stat-label {
    font-size: 11px;
    font-weight: 500;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    color: rgba(255, 255, 255, 0.3);
  }

  .hero-stat-divider {
    width: 1px;
    height: 36px;
    background: rgba(255, 255, 255, 0.06);
  }

  /* Sections */
  .section {
    margin-bottom: 28px;
  }

  .section-head {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 10px;
    padding: 0 2px;
  }

  .section-label {
    font-size: 11px;
    font-weight: 600;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    color: rgba(255, 255, 255, 0.3);
  }

  .section-count {
    font-size: 10px;
    font-weight: 600;
    color: rgba(255, 255, 255, 0.2);
    background: rgba(255, 255, 255, 0.04);
    padding: 1px 6px;
    border-radius: 3px;
  }

  .section-hint {
    font-size: 13px;
    color: rgba(255, 255, 255, 0.25);
    margin: -4px 0 10px 2px;
    line-height: 1.4;
  }

  /* Page Cards */
  .card-list {
    display: flex;
    flex-direction: column;
    gap: 1px;
    background: rgba(255, 255, 255, 0.04);
    border-radius: 10px;
    overflow: hidden;
  }

  .page-card {
    background: rgba(255, 255, 255, 0.02);
    padding: 14px 16px;
    animation: fade-up 0.3s ease both;
  }

  .page-card:hover {
    background: rgba(255, 255, 255, 0.035);
  }

  .page-card-main {
    display: flex;
    align-items: center;
    gap: 10px;
  }

  .page-card-icon {
    color: rgba(255, 255, 255, 0.2);
    flex-shrink: 0;
    display: flex;
  }

  .page-card-name {
    font-size: 14px;
    font-weight: 500;
    color: rgba(255, 255, 255, 0.85);
    flex: 1;
    min-width: 0;
  }

  .page-card-meta {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-shrink: 0;
  }

  .page-card-meta span {
    font-size: 12px;
    color: rgba(255, 255, 255, 0.2);
    white-space: nowrap;
  }

  .page-card-sections {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
    margin-top: 8px;
    padding-left: 25px;
  }

  .section-chip {
    font-size: 11px;
    color: rgba(255, 255, 255, 0.4);
    background: rgba(255, 255, 255, 0.04);
    padding: 2px 8px;
    border-radius: 4px;
    line-height: 1.5;
  }

  .show-more {
    background: transparent;
    border: none;
    color: rgba(74, 158, 122, 0.7);
    font-size: 13px;
    font-weight: 500;
    font-family: inherit;
    padding: 12px 16px;
    cursor: pointer;
    text-align: center;
    transition: color 0.15s;
  }

  .show-more:hover {
    color: #4a9e7a;
  }

  /* Simple Lists (partials, menus) */
  .simple-list {
    background: rgba(255, 255, 255, 0.02);
    border: 1px solid rgba(255, 255, 255, 0.04);
    border-radius: 10px;
    overflow: hidden;
  }

  .simple-row {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 11px 16px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.03);
    color: rgba(255, 255, 255, 0.25);
  }

  .simple-row:last-child {
    border-bottom: none;
  }

  .simple-name {
    font-size: 13px;
    font-weight: 500;
    color: rgba(255, 255, 255, 0.7);
  }

  /* Navigation rows */
  .nav-row {
    padding: 12px 16px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.03);
  }

  .nav-row:last-child {
    border-bottom: none;
  }

  .nav-row-top {
    display: flex;
    align-items: center;
    gap: 10px;
    color: rgba(255, 255, 255, 0.25);
  }

  .nav-item-count {
    font-size: 12px;
    color: rgba(255, 255, 255, 0.2);
    margin-left: auto;
  }

  .nav-labels {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-top: 8px;
    padding-left: 24px;
  }

  .nav-label {
    font-size: 12px;
    color: rgba(255, 255, 255, 0.4);
  }

  .nav-label:not(:last-child)::after {
    content: '\00b7';
    margin-left: 6px;
    color: rgba(255, 255, 255, 0.15);
  }

  /* Globals Grid */
  .globals-grid {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
  }

  .global-item {
    display: flex;
    align-items: center;
    gap: 6px;
    background: rgba(255, 255, 255, 0.03);
    border: 1px solid rgba(255, 255, 255, 0.04);
    padding: 5px 10px;
    border-radius: 6px;
  }

  .global-name {
    font-size: 12px;
    color: rgba(255, 255, 255, 0.55);
    font-weight: 500;
  }

  .global-type {
    font-size: 10px;
    color: rgba(255, 255, 255, 0.2);
    text-transform: uppercase;
    letter-spacing: 0.04em;
  }

  /* Warnings */
  .warning-row {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 10px 16px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.03);
    font-size: 13px;
    color: #d4a017;
  }

  .warning-row:last-child {
    border-bottom: none;
  }

  .warning-row svg {
    flex-shrink: 0;
    margin-top: 2px;
  }

  /* Forge Actions */
  .forge-actions {
    margin-top: 36px;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 12px;
    padding-bottom: 8px;
  }

  /* ========================================
     Empty State
     ======================================== */
  .empty-circle {
    width: 64px;
    height: 64px;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.03);
    display: flex;
    align-items: center;
    justify-content: center;
    color: rgba(255, 255, 255, 0.2);
    margin-bottom: 24px;
  }

  /* ========================================
     Step 3: Complete
     ======================================== */
  .complete-check {
    width: 72px;
    height: 72px;
    border-radius: 50%;
    background: rgba(45, 90, 71, 0.12);
    display: flex;
    align-items: center;
    justify-content: center;
    color: #4a9e7a;
    margin-bottom: 28px;
    opacity: 0;
    transform: scale(0.6);
    transition: opacity 0.4s ease, transform 0.5s cubic-bezier(0.34, 1.56, 0.64, 1);
  }

  .complete-check.visible {
    opacity: 1;
    transform: scale(1);
  }

  .complete-summary {
    margin-bottom: 36px;
    opacity: 0;
    transform: translateY(8px);
    transition: opacity 0.4s ease, transform 0.4s ease;
  }

  .complete-summary.visible {
    opacity: 1;
    transform: translateY(0);
  }

  .complete-desc {
    font-size: 15px;
    color: rgba(255, 255, 255, 0.35);
    margin: 0;
  }

  .summary-stats {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 32px;
  }

  .summary-item {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 2px;
  }

  .summary-num {
    font-size: 24px;
    font-weight: 700;
    color: #fff;
    letter-spacing: -0.5px;
    line-height: 1;
  }

  .summary-text {
    font-size: 12px;
    color: rgba(255, 255, 255, 0.3);
  }

  .complete-action {
    opacity: 0;
    transform: translateY(8px);
    transition: opacity 0.4s ease, transform 0.4s ease;
  }

  .complete-action.visible {
    opacity: 1;
    transform: translateY(0);
  }

  /* ========================================
     Responsive
     ======================================== */
  @media (max-width: 480px) {
    .wizard-shell {
      padding: 40px 16px 60px;
    }

    .hero-heading {
      font-size: 26px;
    }

    .hero-stat-num {
      font-size: 28px;
    }

    .hero-stats {
      padding: 20px 0;
    }

    .name-input {
      font-size: 18px;
    }

    .forge-heading {
      font-size: 22px;
    }

    .page-card-meta {
      gap: 8px;
    }

    .page-card-meta span {
      font-size: 11px;
    }

    .summary-stats {
      gap: 20px;
    }

    .globals-grid {
      gap: 4px;
    }

    .global-item {
      padding: 4px 8px;
    }
  }
</style>
