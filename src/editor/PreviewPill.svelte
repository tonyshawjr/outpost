<script>
  let { onexit = () => {} } = $props();
</script>

<div class="ope-preview-pill" onclick={onexit}>
  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
    <circle cx="12" cy="12" r="3"/>
  </svg>
  <span class="ope-preview-pill-text">Preview Mode</span>
  <span class="ope-preview-pill-hint">Click or press Esc to exit</span>
</div>

<style>
  .ope-preview-pill {
    position: fixed;
    bottom: 24px;
    left: 50%;
    transform: translateX(-50%);
    z-index: 2147483647;
    pointer-events: auto;
    display: flex;
    align-items: center;
    gap: 8px;
    background: rgba(15, 15, 15, 0.92);
    backdrop-filter: blur(12px);
    -webkit-backdrop-filter: blur(12px);
    padding: 10px 20px;
    border-radius: 100px;
    box-shadow: 0 4px 24px rgba(0, 0, 0, 0.35);
    border: 1px solid rgba(255, 255, 255, 0.09);
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Inter', sans-serif;
    cursor: pointer;
    transition: background 0.15s;
    animation: ope-preview-fadein 0.2s ease-out;
    color: rgba(255, 255, 255, 0.85);
  }

  .ope-preview-pill:hover {
    background: rgba(15, 15, 15, 0.98);
  }

  .ope-preview-pill-text {
    font-size: 13px;
    font-weight: 500;
    white-space: nowrap;
  }

  .ope-preview-pill-hint {
    font-size: 11px;
    color: rgba(255, 255, 255, 0.45);
    margin-left: 4px;
    white-space: nowrap;
  }

  @keyframes ope-preview-fadein {
    from { opacity: 0; transform: translateX(-50%) translateY(10px); }
    to { opacity: 1; transform: translateX(-50%) translateY(0); }
  }
</style>
