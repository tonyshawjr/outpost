<script>
  import { toasts, removeToast } from '$lib/stores.js';

  let items = $derived($toasts);
</script>

<div class="toast-container">
  {#each items as toast (toast.id)}
    <div class="toast toast-{toast.type}" role="alert">
      <span>{toast.message}</span>
      <button
        class="btn btn-ghost btn-sm"
        onclick={() => removeToast(toast.id)}
        aria-label="Dismiss"
        style="margin-left: auto; padding: 2px;"
      >
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>
  {/each}
</div>
