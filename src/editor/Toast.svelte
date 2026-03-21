<script>
  let { message = '' } = $props();
</script>

<div class="ope-toast">
  <span>{message}</span>
</div>

<style>
  .ope-toast {
    position: fixed;
    bottom: 24px;
    left: 50%;
    transform: translateX(-50%);
    z-index: 2147483647;
    pointer-events: none;
    background: rgba(15, 15, 15, 0.92);
    backdrop-filter: blur(12px);
    -webkit-backdrop-filter: blur(12px);
    color: rgba(255, 255, 255, 0.9);
    font-size: 13px;
    font-weight: 500;
    padding: 10px 20px;
    border-radius: 100px;
    box-shadow: 0 4px 24px rgba(0, 0, 0, 0.35);
    border: 1px solid rgba(255, 255, 255, 0.09);
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Inter', sans-serif;
    animation: ope-toast-in 0.2s ease;
  }

  @keyframes ope-toast-in {
    from { opacity: 0; transform: translateX(-50%) translateY(8px); }
    to { opacity: 1; transform: translateX(-50%) translateY(0); }
  }
</style>
