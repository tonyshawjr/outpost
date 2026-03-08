<script>
  import { isDismissed, dismissTip } from '$lib/tips.js';

  let { tipKey = '', message = '' } = $props();
  let dismissed = $state(true);

  // Check on mount (can't use onMount for initial render)
  $effect(() => {
    dismissed = isDismissed(tipKey);
  });

  function dismiss() {
    dismissTip(tipKey);
    dismissed = true;
  }
</script>

{#if !dismissed && message}
  <div class="tip-bar" role="note">
    <span class="tip-text">{message}</span>
    <button class="tip-close" onclick={dismiss} aria-label="Dismiss tip">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
    </button>
  </div>
{/if}

<style>
  .tip-bar {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 16px;
    background: var(--bg-hover);
    border-radius: 6px;
    margin-bottom: 20px;
    animation: tipFadeIn 0.2s ease;
  }
  .tip-text {
    flex: 1;
    font-size: 13px;
    color: var(--text-secondary);
    line-height: 1.4;
  }
  .tip-close {
    background: none;
    border: none;
    color: var(--text-tertiary);
    cursor: pointer;
    padding: 4px;
    border-radius: 4px;
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: center;
  }
  .tip-close:hover {
    color: var(--text-secondary);
    background: var(--bg-secondary);
  }
  @keyframes tipFadeIn {
    from { opacity: 0; transform: translateY(-4px); }
    to { opacity: 1; transform: translateY(0); }
  }
</style>
