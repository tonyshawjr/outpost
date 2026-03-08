<script>
  let { summary = null, onFinish } = $props();
</script>

<div class="wizard-step">
  <div class="check-circle">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="40" height="40">
      <polyline points="20 6 9 17 4 12"/>
    </svg>
  </div>
  <h2 class="wizard-heading">You're all set!</h2>
  {#if summary}
    <p class="wizard-desc">
      Created {summary.pages || 0} pages, {summary.items || 0} content items, and {summary.menus || 0} menus.
    </p>
  {:else}
    <p class="wizard-desc">Your site is ready to customize.</p>
  {/if}
  <div class="wizard-actions">
    <button class="btn btn-primary" onclick={onFinish}>Go to Dashboard</button>
  </div>
</div>

<style>
  .wizard-step {
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
  }
  .check-circle {
    width: 72px;
    height: 72px;
    border-radius: 50%;
    background: rgba(91, 140, 90, 0.15);
    display: flex;
    align-items: center;
    justify-content: center;
    color: #5b8c5a;
    margin-bottom: 24px;
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
  .wizard-actions {
    margin-top: 8px;
  }
</style>
