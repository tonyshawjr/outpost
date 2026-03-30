<script>
  import { onMount } from 'svelte';
  import { setup as setupApi, forge as forgeApi } from '$lib/api.js';
  import { setupCompleted, navigate, addToast } from '$lib/stores.js';
  import WizardStepName from './setup/WizardStepName.svelte';
  import WizardStepComplete from './setup/WizardStepComplete.svelte';

  let step = $state(1);
  let siteName = $state('');
  let applying = $state(false);
  let summary = $state(null);

  // Forge analysis state
  let analyzing = $state(false);
  let analysis = $state(null);
  let forgeApplying = $state(false);
  let forgeResult = $state(null);

  async function nextStep() {
    if (step === 1 && !applying) {
      await applySetup();
    }
  }

  async function applySetup() {
    applying = true;
    try {
      const data = await setupApi.apply({
        site_name: siteName.trim(),
      });
      summary = data.summary || null;
      setupCompleted.set(true);
      // After setup, run Forge analysis automatically
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
      // If Forge fails, just go to dashboard — site still works
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
</script>

<div class="wizard-shell">
  <div class="wizard-container">
    {#if step < 3}
      <button class="wizard-skip" onclick={skip}>Skip setup</button>
    {/if}

    <!-- Progress dots -->
    {#if step < 3}
      <div class="wizard-progress">
        {#each [1, 2] as n}
          <div class="dot" class:active={step === n} class:done={step > n}></div>
        {/each}
      </div>
    {/if}

    <div class="wizard-body">
      {#if step === 1}
        {#if applying}
          <div class="wizard-loading">
            <div class="spinner"></div>
            <p class="wizard-loading-text">Setting up your site...</p>
          </div>
        {:else}
          <WizardStepName bind:siteName onNext={nextStep} />
        {/if}

      {:else if step === 2}
        <div class="forge-step">
          {#if analyzing}
            <div class="wizard-loading">
              <div class="spinner"></div>
              <p class="wizard-loading-text">Analyzing your site files...</p>
            </div>
          {:else if analysis}
            <div class="forge-preview">
              <h2 class="forge-title">Forge</h2>
              <p class="forge-subtitle">We scanned your site and found editable content.</p>

              <div class="forge-stats">
                <div class="forge-stat">
                  <span class="forge-stat-num">{analysis.summary?.total_pages ?? 0}</span>
                  <span class="forge-stat-label">Pages</span>
                </div>
                <div class="forge-stat">
                  <span class="forge-stat-num">{analysis.summary?.total_fields ?? 0}</span>
                  <span class="forge-stat-label">Fields</span>
                </div>
                <div class="forge-stat">
                  <span class="forge-stat-num">{analysis.summary?.total_partials ?? 0}</span>
                  <span class="forge-stat-label">Partials</span>
                </div>
                <div class="forge-stat">
                  <span class="forge-stat-num">{analysis.menus?.length ?? 0}</span>
                  <span class="forge-stat-label">Menus</span>
                </div>
                <div class="forge-stat">
                  <span class="forge-stat-num">{analysis.summary?.total_globals ?? 0}</span>
                  <span class="forge-stat-label">Globals</span>
                </div>
              </div>

              {#if analysis.partials?.length}
                <div class="forge-section">
                  <p class="forge-section-label">Shared Partials</p>
                  {#each analysis.partials as partial}
                    <div class="forge-item">{partial.name}</div>
                  {/each}
                </div>
              {/if}

              {#if analysis.menus?.length}
                <div class="forge-section">
                  <p class="forge-section-label">Navigation Menus</p>
                  {#each analysis.menus as menu}
                    <div class="forge-item">{menu.name} — {menu.items?.length ?? 0} items</div>
                  {/each}
                </div>
              {/if}

              {#if analysis.warnings?.length}
                <div class="forge-section">
                  <p class="forge-section-label">Warnings</p>
                  {#each analysis.warnings as warning}
                    <div class="forge-warning">{warning}</div>
                  {/each}
                </div>
              {/if}

              <div class="forge-actions">
                <button class="forge-btn forge-btn-primary" onclick={applyForge} disabled={forgeApplying}>
                  {forgeApplying ? 'Applying...' : 'Apply Forge'}
                </button>
                <button class="forge-btn forge-btn-skip" onclick={skipForge}>
                  Skip — I'll do this later
                </button>
              </div>
            </div>
          {:else}
            <!-- Analysis returned nothing or failed -->
            <div class="forge-preview">
              <h2 class="forge-title">No HTML files found</h2>
              <p class="forge-subtitle">Your site doesn't have any HTML files yet, or they're already set up for Outpost.</p>
              <div class="forge-actions">
                <button class="forge-btn forge-btn-primary" onclick={skipForge}>Continue</button>
              </div>
            </div>
          {/if}
        </div>

      {:else if step === 3}
        <WizardStepComplete {summary} onFinish={finish} />
      {/if}
    </div>
  </div>
</div>

<style>
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
    max-width: 640px;
    position: relative;
  }
  .wizard-skip {
    position: absolute;
    top: -32px;
    right: 0;
    background: none;
    border: none;
    color: rgba(255, 255, 255, 0.3);
    font-size: 13px;
    cursor: pointer;
    padding: 4px 8px;
  }
  .wizard-skip:hover {
    color: rgba(255, 255, 255, 0.6);
  }
  .wizard-progress {
    display: flex;
    justify-content: center;
    gap: 8px;
    margin-bottom: 48px;
  }
  .dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.15);
    transition: background 0.2s;
  }
  .dot.active {
    background: rgba(255, 255, 255, 0.8);
  }
  .dot.done {
    background: rgba(255, 255, 255, 0.4);
  }
  .wizard-body {
    min-height: 300px;
    display: flex;
    align-items: center;
    justify-content: center;
  }
  .wizard-loading {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 16px;
  }
  .wizard-loading-text {
    color: rgba(255, 255, 255, 0.5);
    font-size: 15px;
  }
  .wizard-loading :global(.spinner) {
    border-color: rgba(255, 255, 255, 0.15);
    border-top-color: rgba(255, 255, 255, 0.7);
  }

  /* Forge step */
  .forge-step {
    width: 100%;
  }
  .forge-preview {
    text-align: center;
  }
  .forge-title {
    font-size: 28px;
    font-weight: 700;
    color: #fff;
    margin-bottom: 8px;
    letter-spacing: -0.5px;
  }
  .forge-subtitle {
    font-size: 15px;
    color: rgba(255, 255, 255, 0.5);
    margin-bottom: 32px;
  }
  .forge-stats {
    display: flex;
    justify-content: center;
    gap: 32px;
    margin-bottom: 32px;
  }
  .forge-stat {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 4px;
  }
  .forge-stat-num {
    font-size: 28px;
    font-weight: 700;
    color: #fff;
  }
  .forge-stat-label {
    font-size: 11px;
    font-weight: 600;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    color: rgba(255, 255, 255, 0.35);
  }
  .forge-section {
    text-align: left;
    margin-bottom: 20px;
    padding: 16px;
    background: rgba(255, 255, 255, 0.04);
    border-radius: 8px;
  }
  .forge-section-label {
    font-size: 11px;
    font-weight: 600;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    color: rgba(255, 255, 255, 0.35);
    margin-bottom: 8px;
  }
  .forge-item {
    font-size: 14px;
    color: rgba(255, 255, 255, 0.7);
    padding: 4px 0;
  }
  .forge-warning {
    font-size: 13px;
    color: #e6a817;
    padding: 4px 0;
  }
  .forge-actions {
    margin-top: 32px;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 12px;
  }
  .forge-btn {
    border: none;
    border-radius: 8px;
    font-size: 16px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.15s;
  }
  .forge-btn-primary {
    background: #2D5A47;
    color: #fff;
    padding: 14px 48px;
    width: 100%;
    max-width: 320px;
  }
  .forge-btn-primary:hover {
    background: #1E3D30;
  }
  .forge-btn-primary:disabled {
    background: #555;
    cursor: not-allowed;
  }
  .forge-btn-skip {
    background: none;
    color: rgba(255, 255, 255, 0.3);
    padding: 8px 16px;
    font-size: 13px;
  }
  .forge-btn-skip:hover {
    color: rgba(255, 255, 255, 0.6);
  }
</style>
