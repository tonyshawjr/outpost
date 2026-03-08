<script>
  let { packs = [], selectedPack = $bindable(''), onNext, onBack } = $props();

  const iconMap = {
    pencil: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="24" height="24"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>',
    grid: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="24" height="24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>',
    briefcase: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="24" height="24"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v2"/></svg>',
    plus: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="24" height="24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>',
  };
</script>

<div class="wizard-step">
  <h2 class="wizard-heading">Add starter content?</h2>
  <p class="wizard-desc">Choose a content pack or start fresh. You can delete anything later.</p>

  <div class="pack-cards">
    {#each packs as pack}
      <button
        class="pack-card"
        class:selected={selectedPack === pack.id}
        onclick={() => selectedPack = pack.id}
      >
        <div class="pack-icon">{@html iconMap[pack.icon] || iconMap.plus}</div>
        <div class="pack-name">{pack.name}</div>
        <div class="pack-desc">{pack.description}</div>
      </button>
    {/each}
  </div>

  <div class="wizard-actions">
    <button class="wizard-back" onclick={onBack}>Back</button>
    <button class="btn btn-primary" onclick={onNext} disabled={!selectedPack}>Continue</button>
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
  .pack-cards {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 12px;
    width: 100%;
  }
  .pack-card {
    padding: 20px;
    background: rgba(255, 255, 255, 0.04);
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 10px;
    cursor: pointer;
    transition: all 0.15s;
    text-align: left;
    color: #fff;
  }
  .pack-card:hover {
    background: rgba(255, 255, 255, 0.07);
    border-color: rgba(255, 255, 255, 0.2);
  }
  .pack-card.selected {
    border-color: rgba(255, 255, 255, 0.5);
    background: rgba(255, 255, 255, 0.08);
  }
  .pack-icon {
    color: rgba(255, 255, 255, 0.4);
    margin-bottom: 10px;
  }
  .pack-name {
    font-weight: 600;
    font-size: 15px;
    margin-bottom: 4px;
  }
  .pack-desc {
    font-size: 13px;
    color: rgba(255, 255, 255, 0.4);
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

  @media (max-width: 480px) {
    .pack-cards {
      grid-template-columns: 1fr;
    }
  }
</style>
