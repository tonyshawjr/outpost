<script>
  let { selectedTheme = $bindable(''), onNext, onBack } = $props();

  const themes = [
    {
      slug: 'personal',
      name: 'Personal',
      desc: 'Blog, portfolio, and resume. Great for creators.',
    },
    {
      slug: 'starter',
      name: 'Starter',
      desc: 'Multi-page business site with features and contact.',
    },
    {
      slug: 'skeleton',
      name: 'Skeleton',
      desc: 'Bare-bones starter for developers. Build from scratch.',
    },
  ];

  function select(slug) {
    selectedTheme = slug;
  }
</script>

<div class="wizard-step">
  <h2 class="wizard-heading">Choose a theme</h2>
  <p class="wizard-desc">Pick a starting point. You can customize everything later.</p>

  <div class="theme-cards">
    {#each themes as theme}
      <button
        class="theme-card"
        class:selected={selectedTheme === theme.slug}
        onclick={() => select(theme.slug)}
      >
        <div class="theme-card-name">{theme.name}</div>
        <div class="theme-card-desc">{theme.desc}</div>
      </button>
    {/each}
  </div>

  <div class="wizard-actions">
    <button class="wizard-back" onclick={onBack}>Back</button>
    <button class="btn btn-primary" onclick={onNext} disabled={!selectedTheme}>Continue</button>
  </div>
</div>

<style>
  .wizard-step {
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
  }
  .wizard-heading {
    font-family: var(--font-serif);
    font-size: 28px;
    font-weight: 600;
    color: #fff;
    margin: 0 0 8px;
  }
  .wizard-desc {
    font-size: 15px;
    color: rgba(255, 255, 255, 0.5);
    margin: 0 0 32px;
  }
  .theme-cards {
    display: flex;
    gap: 16px;
    width: 100%;
    flex-wrap: wrap;
    justify-content: center;
  }
  .theme-card {
    flex: 1;
    min-width: 160px;
    max-width: 200px;
    padding: 24px 20px;
    background: rgba(255, 255, 255, 0.04);
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 10px;
    cursor: pointer;
    transition: all 0.15s;
    text-align: left;
    color: #fff;
  }
  .theme-card:hover {
    background: rgba(255, 255, 255, 0.07);
    border-color: rgba(255, 255, 255, 0.2);
  }
  .theme-card.selected {
    border-color: rgba(255, 255, 255, 0.5);
    background: rgba(255, 255, 255, 0.08);
  }
  .theme-card-name {
    font-family: var(--font-serif);
    font-size: 17px;
    font-weight: 600;
    margin-bottom: 6px;
  }
  .theme-card-desc {
    font-size: 13px;
    color: rgba(255, 255, 255, 0.45);
    line-height: 1.4;
  }
  .wizard-actions {
    margin-top: 32px;
    display: flex;
    align-items: center;
    gap: 16px;
  }
  .wizard-back {
    background: none;
    border: none;
    color: rgba(255, 255, 255, 0.4);
    font-size: 14px;
    cursor: pointer;
    padding: 8px 12px;
  }
  .wizard-back:hover {
    color: rgba(255, 255, 255, 0.7);
  }
</style>
