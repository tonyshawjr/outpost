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

  // Auto-detect site name on mount
  onMount(async () => {
    try {
      const data = await siteApi.detect();
      if (data.site_name) {
        siteName = data.site_name;
        detected = true;
      }
    } catch (e) {
      // Detection failed silently — user can type manually
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
    } catch (e) {
      addToast('Forge error: ' + e.message, 'error');
    } finally {
      forgeApplying = false;
    }
  }

  function skipForge() {
    step = 3;
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
    navigate('dashboard');
  }

  function formatPageName(file) {
    const name = file.replace(/\.html$/, '').replace(/[_-]/g, ' ');
    if (name === 'index') return 'Home';
    return name.split(' ').map(w => w.charAt(0).toUpperCase() + w.slice(1)).join(' ');
  }
</script>

<div class="wizard-shell">
  <div class="wizard-container" class:wizard-wide={step === 2 && !analyzing && analysis}>
    {#if step < 3}
      <button class="wizard-skip" onclick={skip}>Skip setup</button>
    {/if}

    <!-- Progress indicator -->
    {#if step < 3}
      <div class="wizard-progress">
        {#each [1, 2, 3] as n}
          <div class="progress-step" class:active={step === n} class:done={step > n}>
            <div class="progress-dot">
              {#if step > n}
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" width="10" height="10">
                  <polyline points="20 6 9 17 4 12"/>
                </svg>
              {/if}
            </div>
            {#if n < 3}
              <div class="progress-line" class:filled={step > n}></div>
            {/if}
          </div>
        {/each}
      </div>
    {/if}

    <div class="wizard-body">
      <!-- STEP 1: Site Name -->
      {#if step === 1}
        {#if applying}
          <div class="wizard-loading">
            <div class="spinner"></div>
            <p class="loading-text">Setting up your site...</p>
          </div>
        {:else}
          <div class="step-name">
            <div class="step-icon">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="28" height="28">
                <path d="M12 2L2 7l10 5 10-5-10-5z"/>
                <path d="M2 17l10 5 10-5"/>
                <path d="M2 12l10 5 10-5"/>
              </svg>
            </div>
            <h2 class="step-heading">What's your site called?</h2>
            <p class="step-desc">We'll use this as your site name across the CMS.</p>

            <div class="name-field-wrap">
              {#if detecting}
                <div class="detecting-indicator">
                  <div class="detecting-dot"></div>
                  <span>Detecting from your site...</span>
                </div>
              {:else}
                <input
                  class="name-input"
                  type="text"
                  placeholder="My Website"
                  bind:value={siteName}
                  onkeydown={handleKeydown}
                  autofocus
                  aria-label="Site name"
                  maxlength="200"
                />
                {#if detected && siteName}
                  <p class="detected-hint">Detected from your index.html</p>
                {/if}
              {/if}
            </div>

            <div class="step-actions">
              <button class="wizard-btn wizard-btn-primary" onclick={nextStep} disabled={!siteName.trim() || detecting}>
                Continue
              </button>
            </div>
          </div>
        {/if}

      <!-- STEP 2: Forge Analysis -->
      {:else if step === 2}
        <div class="step-forge">
          {#if analyzing}
            <div class="scan-loading">
              <div class="scan-icon-wrap">
                <div class="scan-ring"></div>
                <svg class="scan-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="24" height="24">
                  <circle cx="11" cy="11" r="8"/>
                  <path d="M21 21l-4.35-4.35"/>
                </svg>
              </div>
              <h2 class="scan-title">Scanning your site</h2>
              <p class="scan-subtitle">Analyzing templates, fields, and navigation...</p>
              <div class="scan-bar-track">
                <div class="scan-bar-fill"></div>
              </div>
            </div>
          {:else if analysis && stats()}
            <!-- Results -->
            <div class="forge-results">
              <div class="forge-header">
                <h2 class="forge-title">Your site is ready for Outpost</h2>
                <p class="forge-subtitle">We found everything we need to make your content editable.</p>
              </div>

              <!-- Stat cards -->
              <div class="stat-cards">
                <div class="stat-card">
                  <span class="stat-value">{stats().pages}</span>
                  <span class="stat-label">Pages</span>
                </div>
                <div class="stat-card">
                  <span class="stat-value">{stats().fields}</span>
                  <span class="stat-label">Fields</span>
                </div>
                <div class="stat-card">
                  <span class="stat-value">{stats().partials}</span>
                  <span class="stat-label">Partials</span>
                </div>
                <div class="stat-card">
                  <span class="stat-value">{stats().menus}</span>
                  <span class="stat-label">Menus</span>
                </div>
                <div class="stat-card">
                  <span class="stat-value">{stats().globals}</span>
                  <span class="stat-label">Globals</span>
                </div>
              </div>

              <!-- Pages detail list -->
              {#if analysis.pages?.length}
                <div class="detail-section">
                  <div class="detail-label">Pages</div>
                  <div class="detail-list">
                    {#each analysis.pages as page}
                      <div class="page-row">
                        <div class="page-row-left">
                          <svg class="page-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="16" height="16">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                            <polyline points="14 2 14 8 20 8"/>
                          </svg>
                          <div class="page-info">
                            <span class="page-name">{formatPageName(page.file)}</span>
                            <span class="page-file">{page.file}</span>
                          </div>
                        </div>
                        <div class="page-row-right">
                          {#if page.sections?.length}
                            <span class="page-meta">{page.sections.length} {page.sections.length === 1 ? 'section' : 'sections'}</span>
                          {/if}
                          <span class="page-meta">{page.fields?.length || 0} fields</span>
                        </div>
                      </div>
                      {#if page.sections?.length}
                        <div class="section-tags">
                          {#each page.sections as section}
                            <span class="section-tag">{section}</span>
                          {/each}
                        </div>
                      {/if}
                    {/each}
                  </div>
                </div>
              {/if}

              <!-- Partials -->
              {#if analysis.partials?.length}
                <div class="detail-section">
                  <div class="detail-label">Shared Partials</div>
                  <div class="detail-list">
                    {#each analysis.partials as partial}
                      <div class="detail-row">
                        <div class="detail-row-left">
                          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="14" height="14">
                            <rect x="3" y="3" width="18" height="18" rx="2" ry="2"/>
                            <line x1="3" y1="9" x2="21" y2="9"/>
                          </svg>
                          <span class="detail-name">{partial.name || partial.file}</span>
                        </div>
                        {#if partial.lines}
                          <span class="detail-meta">{partial.lines} lines</span>
                        {/if}
                      </div>
                    {/each}
                  </div>
                </div>
              {/if}

              <!-- Navigation -->
              {#if analysis.menus?.length}
                <div class="detail-section">
                  <div class="detail-label">Navigation</div>
                  <div class="detail-list">
                    {#each analysis.menus as menu}
                      <div class="detail-row">
                        <div class="detail-row-left">
                          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="14" height="14">
                            <line x1="3" y1="12" x2="21" y2="12"/>
                            <line x1="3" y1="6" x2="21" y2="6"/>
                            <line x1="3" y1="18" x2="21" y2="18"/>
                          </svg>
                          <span class="detail-name">{menu.name}</span>
                        </div>
                        <span class="detail-meta">{menu.items?.length ?? 0} items</span>
                      </div>
                      {#if menu.items?.length}
                        <div class="nav-items">
                          {#each menu.items as item}
                            <div class="nav-item">
                              <span class="nav-item-label">{item.label || item.url || 'Link'}</span>
                              {#if item.url}
                                <span class="nav-item-url">{item.url}</span>
                              {/if}
                            </div>
                          {/each}
                        </div>
                      {/if}
                    {/each}
                  </div>
                </div>
              {/if}

              <!-- Globals -->
              {#if analysis.globals?.length}
                <div class="detail-section">
                  <div class="detail-label">Global Fields</div>
                  <div class="detail-list">
                    {#each analysis.globals as g}
                      <div class="detail-row">
                        <div class="detail-row-left">
                          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="14" height="14">
                            <circle cx="12" cy="12" r="10"/>
                            <line x1="2" y1="12" x2="22" y2="12"/>
                            <path d="M12 2a15.3 15.3 0 014 10 15.3 15.3 0 01-4 10 15.3 15.3 0 01-4-10 15.3 15.3 0 014-10z"/>
                          </svg>
                          <span class="detail-name">{g.name || g.field}</span>
                        </div>
                        {#if g.type}
                          <span class="detail-meta">{g.type}</span>
                        {/if}
                      </div>
                    {/each}
                  </div>
                </div>
              {/if}

              <!-- Warnings -->
              {#if analysis.warnings?.length}
                <div class="detail-section">
                  <div class="detail-label">Warnings</div>
                  <div class="detail-list">
                    {#each analysis.warnings as warning}
                      <div class="warning-row">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
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
                <button class="wizard-btn wizard-btn-primary wizard-btn-lg" onclick={applyForge} disabled={forgeApplying}>
                  {#if forgeApplying}
                    <div class="btn-spinner"></div>
                    Applying Forge...
                  {:else}
                    Apply Forge
                  {/if}
                </button>
                <button class="wizard-btn wizard-btn-ghost" onclick={skipForge}>
                  Skip — I'll set this up later
                </button>
              </div>
            </div>
          {:else}
            <!-- No HTML files found -->
            <div class="scan-empty">
              <div class="empty-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="32" height="32">
                  <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                  <polyline points="14 2 14 8 20 8"/>
                  <line x1="9" y1="15" x2="15" y2="15"/>
                </svg>
              </div>
              <h2 class="scan-title">No HTML templates found</h2>
              <p class="scan-subtitle">Your site doesn't have HTML files yet, or they're already configured for Outpost.</p>
              <div class="forge-actions">
                <button class="wizard-btn wizard-btn-primary" onclick={skipForge}>Continue to Dashboard</button>
              </div>
            </div>
          {/if}
        </div>

      <!-- STEP 3: Complete -->
      {:else if step === 3}
        <div class="step-complete">
          <div class="complete-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="36" height="36">
              <path d="M22 11.08V12a10 10 0 11-5.93-9.14"/>
              <polyline points="22 4 12 14.01 9 11.01"/>
            </svg>
          </div>
          <h2 class="step-heading">You're all set</h2>
          {#if forgeResult}
            <p class="step-desc">
              Forge created {forgeResult.created_pages || 0} pages, registered {forgeResult.created_fields || 0} fields,
              and set up {forgeResult.created_menus || 0} menus.
            </p>
          {:else}
            <p class="step-desc">Your site is ready. Start editing content from the dashboard.</p>
          {/if}
          <div class="step-actions">
            <button class="wizard-btn wizard-btn-primary wizard-btn-lg" onclick={finish}>
              Go to Dashboard
            </button>
          </div>
        </div>
      {/if}
    </div>
  </div>
</div>

<style>
  /* Shell */
  .wizard-shell {
    position: fixed;
    inset: 0;
    background: #0a0a0a;
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 9999;
    overflow-y: auto;
    padding: 40px 24px;
  }

  .wizard-container {
    width: 100%;
    max-width: 560px;
    position: relative;
    transition: max-width 0.3s ease;
  }

  .wizard-container.wizard-wide {
    max-width: 680px;
  }

  .wizard-skip {
    position: absolute;
    top: -36px;
    right: 0;
    background: none;
    border: none;
    color: rgba(255, 255, 255, 0.25);
    font-size: 13px;
    cursor: pointer;
    padding: 4px 8px;
    transition: color 0.15s;
  }
  .wizard-skip:hover {
    color: rgba(255, 255, 255, 0.5);
  }

  /* Progress */
  .wizard-progress {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 0;
    margin-bottom: 48px;
  }

  .progress-step {
    display: flex;
    align-items: center;
  }

  .progress-dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.12);
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s;
  }

  .progress-step.active .progress-dot {
    background: #fff;
    box-shadow: 0 0 0 4px rgba(255, 255, 255, 0.1);
  }

  .progress-step.done .progress-dot {
    background: #2D5A47;
  }

  .progress-step.done .progress-dot svg {
    color: #fff;
  }

  .progress-line {
    width: 48px;
    height: 1px;
    background: rgba(255, 255, 255, 0.1);
    transition: background 0.2s;
  }

  .progress-line.filled {
    background: #2D5A47;
  }

  /* Body */
  .wizard-body {
    min-height: 320px;
    display: flex;
    align-items: center;
    justify-content: center;
  }

  /* Loading */
  .wizard-loading {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 16px;
  }
  .loading-text {
    color: rgba(255, 255, 255, 0.4);
    font-size: 15px;
    margin: 0;
  }
  .spinner {
    width: 24px;
    height: 24px;
    border: 2px solid rgba(255, 255, 255, 0.1);
    border-top-color: rgba(255, 255, 255, 0.6);
    border-radius: 50%;
    animation: spin 0.7s linear infinite;
  }

  /* Step 1: Name */
  .step-name {
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    width: 100%;
  }

  .step-icon {
    width: 56px;
    height: 56px;
    border-radius: 16px;
    background: rgba(45, 90, 71, 0.15);
    display: flex;
    align-items: center;
    justify-content: center;
    color: #4a9e7a;
    margin-bottom: 24px;
  }

  .step-heading {
    font-family: var(--font-serif, Georgia, serif);
    font-size: 28px;
    font-weight: 600;
    color: #fff;
    margin: 0 0 8px;
    letter-spacing: -0.5px;
  }

  .step-desc {
    font-size: 15px;
    color: rgba(255, 255, 255, 0.4);
    margin: 0 0 32px;
    line-height: 1.5;
  }

  .name-field-wrap {
    width: 100%;
    max-width: 420px;
    min-height: 72px;
    display: flex;
    flex-direction: column;
    align-items: center;
  }

  .detecting-indicator {
    display: flex;
    align-items: center;
    gap: 10px;
    color: rgba(255, 255, 255, 0.35);
    font-size: 14px;
    padding: 18px 0;
  }

  .detecting-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: #4a9e7a;
    animation: pulse-dot 1.2s ease-in-out infinite;
  }

  @keyframes pulse-dot {
    0%, 100% { opacity: 0.3; transform: scale(0.8); }
    50% { opacity: 1; transform: scale(1.2); }
  }

  .name-input {
    width: 100%;
    padding: 14px 20px;
    font-size: 18px;
    font-family: var(--font-serif, Georgia, serif);
    background: rgba(255, 255, 255, 0.05);
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 10px;
    color: #fff;
    outline: none;
    transition: border-color 0.15s, background 0.15s;
    text-align: center;
  }
  .name-input::placeholder {
    color: rgba(255, 255, 255, 0.2);
  }
  .name-input:focus {
    border-color: rgba(255, 255, 255, 0.25);
    background: rgba(255, 255, 255, 0.07);
  }

  .detected-hint {
    font-size: 12px;
    color: rgba(74, 158, 122, 0.7);
    margin: 10px 0 0;
    letter-spacing: 0.02em;
  }

  .step-actions {
    margin-top: 32px;
  }

  /* Buttons */
  .wizard-btn {
    border: none;
    border-radius: 10px;
    font-size: 15px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.15s;
    font-family: inherit;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
  }

  .wizard-btn-primary {
    background: #2D5A47;
    color: #fff;
    padding: 12px 36px;
  }
  .wizard-btn-primary:hover {
    background: #234a39;
  }
  .wizard-btn-primary:disabled {
    background: rgba(255, 255, 255, 0.08);
    color: rgba(255, 255, 255, 0.25);
    cursor: not-allowed;
  }

  .wizard-btn-lg {
    padding: 14px 48px;
    font-size: 16px;
    width: 100%;
    max-width: 360px;
  }

  .wizard-btn-ghost {
    background: none;
    color: rgba(255, 255, 255, 0.3);
    padding: 10px 20px;
    font-size: 13px;
    font-weight: 500;
  }
  .wizard-btn-ghost:hover {
    color: rgba(255, 255, 255, 0.55);
  }

  .btn-spinner {
    width: 16px;
    height: 16px;
    border: 2px solid rgba(255, 255, 255, 0.2);
    border-top-color: #fff;
    border-radius: 50%;
    animation: spin 0.6s linear infinite;
  }

  @keyframes spin {
    to { transform: rotate(360deg); }
  }

  /* Step 2: Forge Scan Loading */
  .step-forge {
    width: 100%;
  }

  .scan-loading {
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    padding: 20px 0;
  }

  .scan-icon-wrap {
    position: relative;
    width: 56px;
    height: 56px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 24px;
  }

  .scan-ring {
    position: absolute;
    inset: 0;
    border: 2px solid rgba(74, 158, 122, 0.15);
    border-top-color: #4a9e7a;
    border-radius: 50%;
    animation: spin 1.2s linear infinite;
  }

  .scan-icon {
    color: rgba(255, 255, 255, 0.6);
  }

  .scan-title {
    font-family: var(--font-serif, Georgia, serif);
    font-size: 22px;
    font-weight: 600;
    color: #fff;
    margin: 0 0 8px;
    letter-spacing: -0.3px;
  }

  .scan-subtitle {
    font-size: 14px;
    color: rgba(255, 255, 255, 0.35);
    margin: 0 0 28px;
  }

  .scan-bar-track {
    width: 200px;
    height: 2px;
    background: rgba(255, 255, 255, 0.08);
    border-radius: 2px;
    overflow: hidden;
  }

  .scan-bar-fill {
    height: 100%;
    background: #2D5A47;
    border-radius: 2px;
    animation: scan-progress 2.5s ease-in-out infinite;
  }

  @keyframes scan-progress {
    0% { width: 0%; transform: translateX(0); }
    50% { width: 70%; }
    100% { width: 100%; transform: translateX(0); }
  }

  /* Scan Empty */
  .scan-empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    padding: 20px 0;
  }

  .empty-icon {
    width: 64px;
    height: 64px;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.04);
    display: flex;
    align-items: center;
    justify-content: center;
    color: rgba(255, 255, 255, 0.3);
    margin-bottom: 20px;
  }

  /* Forge Results */
  .forge-results {
    width: 100%;
  }

  .forge-header {
    text-align: center;
    margin-bottom: 32px;
  }

  .forge-title {
    font-family: var(--font-serif, Georgia, serif);
    font-size: 24px;
    font-weight: 600;
    color: #fff;
    margin: 0 0 8px;
    letter-spacing: -0.3px;
  }

  .forge-subtitle {
    font-size: 14px;
    color: rgba(255, 255, 255, 0.4);
    margin: 0;
  }

  /* Stat Cards */
  .stat-cards {
    display: flex;
    gap: 1px;
    background: rgba(255, 255, 255, 0.06);
    border-radius: 12px;
    overflow: hidden;
    margin-bottom: 32px;
  }

  .stat-card {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 4px;
    padding: 20px 12px;
    background: rgba(255, 255, 255, 0.03);
  }

  .stat-value {
    font-size: 28px;
    font-weight: 700;
    color: #fff;
    letter-spacing: -0.5px;
    line-height: 1;
  }

  .stat-label {
    font-size: 10px;
    font-weight: 600;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    color: rgba(255, 255, 255, 0.3);
  }

  /* Detail sections */
  .detail-section {
    margin-bottom: 20px;
  }

  .detail-label {
    font-size: 10px;
    font-weight: 600;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    color: rgba(255, 255, 255, 0.3);
    margin-bottom: 8px;
    padding: 0 4px;
  }

  .detail-list {
    background: rgba(255, 255, 255, 0.03);
    border: 1px solid rgba(255, 255, 255, 0.06);
    border-radius: 10px;
    overflow: hidden;
  }

  /* Page rows */
  .page-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 16px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.04);
  }

  .page-row:last-child,
  .page-row:has(+ .section-tags) {
    border-bottom: 1px solid rgba(255, 255, 255, 0.04);
  }

  .page-row-left {
    display: flex;
    align-items: center;
    gap: 12px;
    min-width: 0;
  }

  .page-icon {
    color: rgba(255, 255, 255, 0.25);
    flex-shrink: 0;
  }

  .page-info {
    display: flex;
    flex-direction: column;
    min-width: 0;
  }

  .page-name {
    font-size: 14px;
    font-weight: 500;
    color: rgba(255, 255, 255, 0.85);
  }

  .page-file {
    font-size: 11px;
    color: rgba(255, 255, 255, 0.25);
    font-family: var(--font-mono, 'SF Mono', monospace);
  }

  .page-row-right {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-shrink: 0;
  }

  .page-meta {
    font-size: 12px;
    color: rgba(255, 255, 255, 0.3);
  }

  .section-tags {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
    padding: 4px 16px 12px 44px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.04);
  }

  .section-tags:last-child {
    border-bottom: none;
  }

  .section-tag {
    font-size: 11px;
    color: rgba(255, 255, 255, 0.4);
    padding: 2px 8px;
    background: rgba(255, 255, 255, 0.05);
    border-radius: 4px;
    font-family: var(--font-mono, 'SF Mono', monospace);
  }

  /* Detail rows (partials, globals) */
  .detail-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 10px 16px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.04);
  }

  .detail-row:last-child {
    border-bottom: none;
  }

  .detail-row-left {
    display: flex;
    align-items: center;
    gap: 10px;
    color: rgba(255, 255, 255, 0.3);
  }

  .detail-name {
    font-size: 13px;
    font-weight: 500;
    color: rgba(255, 255, 255, 0.75);
  }

  .detail-meta {
    font-size: 12px;
    color: rgba(255, 255, 255, 0.25);
  }

  /* Nav items */
  .nav-items {
    padding: 0 16px 10px 40px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.04);
  }

  .nav-items:last-child {
    border-bottom: none;
  }

  .nav-item {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 3px 0;
  }

  .nav-item-label {
    font-size: 12px;
    color: rgba(255, 255, 255, 0.55);
  }

  .nav-item-url {
    font-size: 11px;
    color: rgba(255, 255, 255, 0.2);
    font-family: var(--font-mono, 'SF Mono', monospace);
  }

  /* Warnings */
  .warning-row {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 10px 16px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.04);
    font-size: 13px;
    color: #d4a017;
  }

  .warning-row:last-child {
    border-bottom: none;
  }

  .warning-row svg {
    flex-shrink: 0;
    margin-top: 1px;
    color: #d4a017;
  }

  /* Forge Actions */
  .forge-actions {
    margin-top: 32px;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 12px;
  }

  /* Step 3: Complete */
  .step-complete {
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
  }

  .complete-icon {
    width: 72px;
    height: 72px;
    border-radius: 50%;
    background: rgba(45, 90, 71, 0.15);
    display: flex;
    align-items: center;
    justify-content: center;
    color: #4a9e7a;
    margin-bottom: 24px;
    animation: check-pop 0.4s ease;
  }

  @keyframes check-pop {
    0% { transform: scale(0.5); opacity: 0; }
    70% { transform: scale(1.1); }
    100% { transform: scale(1); opacity: 1; }
  }
</style>
