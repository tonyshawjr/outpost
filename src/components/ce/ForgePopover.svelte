<script>
  let { x = 0, y = 0, title = '', onClose, children } = $props();

  // Clamp position to keep popover on-screen
  let style = $derived.by(() => {
    const clampX = Math.min(Math.max(x, 8), (typeof window !== 'undefined' ? window.innerWidth : 1200) - 340);
    const clampY = Math.min(Math.max(y, 8), (typeof window !== 'undefined' ? window.innerHeight : 800) - 360);
    return `left:${clampX}px;top:${clampY}px`;
  });
</script>

<svelte:window onkeydown={(e) => { if (e.key === 'Escape') onClose(); }} />

<div class="forge-backdrop" onclick={onClose}></div>
<div class="forge-popover" {style} onclick={(e) => e.stopPropagation()}>
  <div class="forge-pop-header">
    <span class="forge-pop-title">{title}</span>
    <button class="forge-pop-close" onclick={onClose}>
      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
    </button>
  </div>
  <div class="forge-pop-body">
    {@render children()}
  </div>
</div>

<style>
  .forge-backdrop {
    position: fixed;
    inset: 0;
    z-index: 999;
  }

  .forge-popover {
    position: fixed;
    z-index: 1000;
    width: 320px;
    background: var(--bg-primary);
    border: 1px solid var(--border);
    border-radius: 10px;
    box-shadow: 0 8px 32px rgba(0,0,0,.18);
  }

  .forge-pop-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 10px 14px 8px;
    border-bottom: 1px solid var(--border-light);
  }

  .forge-pop-title {
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .06em;
    color: var(--text-secondary);
  }

  .forge-pop-close {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 22px;
    height: 22px;
    background: none;
    border: none;
    border-radius: 4px;
    color: var(--text-muted);
    cursor: pointer;
    transition: background var(--transition-fast), color var(--transition-fast);
  }
  .forge-pop-close:hover { background: var(--bg-hover); color: var(--text); }

  .forge-pop-body {
    padding: 12px 14px 14px;
    display: flex;
    flex-direction: column;
    gap: 10px;
  }

  /* Shared form styles for Forge popover children */
  .forge-pop-body :global(.forge-label) {
    font-size: 10px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .06em;
    color: var(--text-muted);
    margin-bottom: 3px;
    display: block;
  }

  .forge-pop-body :global(.forge-input) {
    width: 100%;
    height: 30px;
    padding: 0 8px;
    font-size: 13px;
    font-family: var(--font-sans);
    color: var(--text);
    background: var(--bg-primary);
    border: 1px solid transparent;
    border-radius: 5px;
    outline: none;
    transition: border-color var(--transition-fast);
    box-sizing: border-box;
  }
  .forge-pop-body :global(.forge-input:hover) { border-color: var(--border); }
  .forge-pop-body :global(.forge-input:focus) { border-color: var(--forest); }

  .forge-pop-body :global(.forge-select) {
    width: 100%;
    height: 30px;
    padding: 0 8px;
    font-size: 13px;
    font-family: var(--font-sans);
    color: var(--text);
    background: var(--bg-primary);
    border: 1px solid transparent;
    border-radius: 5px;
    outline: none;
    transition: border-color var(--transition-fast);
    cursor: pointer;
    appearance: none;
    -webkit-appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg width='10' height='10' viewBox='0 0 24 24' fill='none' stroke='%239a9590' stroke-width='2' xmlns='http://www.w3.org/2000/svg'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 8px center;
    padding-right: 24px;
    box-sizing: border-box;
  }
  .forge-pop-body :global(.forge-select:hover) { border-color: var(--border); }
  .forge-pop-body :global(.forge-select:focus) { border-color: var(--forest); }

  .forge-pop-body :global(.forge-row) {
    display: flex;
    gap: 8px;
  }

  .forge-pop-body :global(.forge-field) {
    display: flex;
    flex-direction: column;
    gap: 3px;
    flex: 1;
    min-width: 0;
  }

  .forge-pop-body :global(.forge-check-row) {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    color: var(--text-secondary);
    cursor: pointer;
  }

  .forge-pop-body :global(.forge-check-row input) {
    margin: 0;
    cursor: pointer;
  }

  .forge-pop-body :global(.forge-actions) {
    display: flex;
    justify-content: flex-end;
    gap: 6px;
    margin-top: 4px;
  }
</style>
