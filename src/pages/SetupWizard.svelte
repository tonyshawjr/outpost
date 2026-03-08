<script>
  import { onMount } from 'svelte';
  import { setup as setupApi } from '$lib/api.js';
  import { setupCompleted, navigate, addToast } from '$lib/stores.js';
  import WizardStepName from './setup/WizardStepName.svelte';
  import WizardStepTheme from './setup/WizardStepTheme.svelte';
  import WizardStepContent from './setup/WizardStepContent.svelte';
  import WizardStepComplete from './setup/WizardStepComplete.svelte';

  let step = $state(1);
  let siteName = $state('');
  let selectedTheme = $state('');
  let selectedPack = $state('');
  let packs = $state([]);
  let applying = $state(false);
  let summary = $state(null);

  // Filter packs by selected theme
  let filteredPacks = $derived(
    packs.filter(p => !p.themes || p.themes.includes(selectedTheme))
  );

  onMount(async () => {
    try {
      const data = await setupApi.packs();
      packs = data.packs || [];
    } catch (e) {
      // Packs endpoint may not exist yet on older installs
      packs = [
        { id: 'blank', name: 'Start from scratch', description: 'Just the theme — no sample content.', icon: 'plus', themes: [] }
      ];
    }
  });

  function nextStep() {
    if (step === 3) {
      applySetup();
    } else {
      step++;
    }
  }

  function prevStep() {
    if (step > 1) step--;
  }

  async function applySetup() {
    applying = true;
    try {
      const data = await setupApi.apply({
        site_name: siteName.trim(),
        theme: selectedTheme,
        pack: selectedPack,
      });
      summary = data.summary || null;
      setupCompleted.set(true);
      step = 4;
    } catch (e) {
      addToast(e.message, 'error');
    } finally {
      applying = false;
    }
  }

  async function skip() {
    try {
      await setupApi.apply({ skip: true });
      setupCompleted.set(true);
      navigate('dashboard');
    } catch (e) {
      // Fallback — just go to dashboard
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
    <!-- Skip link -->
    {#if step < 4}
      <button class="wizard-skip" onclick={skip}>Skip setup</button>
    {/if}

    <!-- Progress dots -->
    {#if step < 4}
      <div class="wizard-progress">
        {#each [1, 2, 3] as n}
          <div class="dot" class:active={step === n} class:done={step > n}></div>
        {/each}
      </div>
    {/if}

    <!-- Steps -->
    <div class="wizard-body">
      {#if step === 1}
        <WizardStepName bind:siteName onNext={nextStep} />
      {:else if step === 2}
        <WizardStepTheme bind:selectedTheme onNext={nextStep} onBack={prevStep} />
      {:else if step === 3}
        {#if applying}
          <div class="wizard-loading">
            <div class="spinner"></div>
            <p class="wizard-loading-text">Setting up your site...</p>
          </div>
        {:else}
          <WizardStepContent packs={filteredPacks} bind:selectedPack onNext={nextStep} onBack={prevStep} />
        {/if}
      {:else if step === 4}
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

  /* Spinner override for dark background */
  .wizard-loading :global(.spinner) {
    border-color: rgba(255, 255, 255, 0.15);
    border-top-color: rgba(255, 255, 255, 0.7);
  }
</style>
