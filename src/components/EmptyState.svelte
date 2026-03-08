<script>
  let {
    icon = '',
    title = '',
    description = '',
    ctaLabel = '',
    ctaAction = null,
    secondaryLabel = '',
    secondaryAction = null,
    searchActive = false,
  } = $props();
</script>

<div class="empty-state-container">
  {#if searchActive}
    <div class="empty-state-title">No results match your search</div>
    <p class="empty-state-desc">Try a different search term or clear your filters.</p>
  {:else}
    {#if icon}
      <div class="empty-state-icon">
        {@html icon}
      </div>
    {/if}
    {#if title}
      <div class="empty-state-title">{title}</div>
    {/if}
    {#if description}
      <p class="empty-state-desc">{description}</p>
    {/if}
    {#if ctaLabel && ctaAction}
      <div class="empty-state-actions">
        <button class="btn btn-primary" onclick={ctaAction}>{ctaLabel}</button>
        {#if secondaryLabel && secondaryAction}
          <button class="btn btn-secondary" onclick={secondaryAction}>{secondaryLabel}</button>
        {/if}
      </div>
    {/if}
  {/if}
</div>

<style>
  .empty-state-container {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    text-align: center;
    padding: var(--space-3xl) var(--space-xl);
  }
  .empty-state-icon {
    color: var(--text-tertiary);
    opacity: 0.4;
    margin-bottom: var(--space-lg);
  }
  .empty-state-icon :global(svg) {
    width: 48px;
    height: 48px;
  }
  .empty-state-title {
    font-family: var(--font-serif);
    font-size: 26px;
    font-weight: 600;
    color: var(--text-primary);
    margin-bottom: var(--space-xs);
  }
  .empty-state-desc {
    font-size: 15px;
    color: var(--text-tertiary);
    margin: 0 0 var(--space-lg);
    max-width: 400px;
  }
  .empty-state-actions {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    justify-content: center;
  }
</style>
