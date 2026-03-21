<script>
  let {
    title = '',
    open = false,
    onclose = () => {},
    children,
  } = $props();
</script>

<div class="ope-drawer" class:ope-drawer-open={open}>
  <div class="ope-drawer-header">
    <span class="ope-drawer-title">{title}</span>
    <button class="ope-drawer-close" onclick={onclose}>
      <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
        <path d="M4 4l8 8M12 4l-8 8" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
      </svg>
    </button>
  </div>
  <div class="ope-drawer-content">
    {@render children()}
  </div>
</div>

<style>
  .ope-drawer {
    position: fixed;
    top: 48px;
    right: 56px;
    width: 400px;
    bottom: 0;
    background: #fff;
    border-left: 1px solid #E5E7EB;
    z-index: 2147483643;
    pointer-events: auto;
    display: flex;
    flex-direction: column;
    transform: translateX(100%);
    transition: transform 0.2s ease-out;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Inter', sans-serif;
  }

  .ope-drawer-open {
    transform: translateX(0);
  }

  .ope-drawer-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    height: 56px;
    padding: 0 20px;
    border-bottom: 1px solid #F3F4F6;
    flex-shrink: 0;
  }

  .ope-drawer-title {
    font-size: 16px;
    font-weight: 600;
    color: #111827;
    letter-spacing: -0.01em;
  }

  .ope-drawer-close {
    width: 28px;
    height: 28px;
    display: flex;
    align-items: center;
    justify-content: center;
    border: none;
    background: transparent;
    color: #9CA3AF;
    border-radius: 6px;
    cursor: pointer;
    padding: 0;
    transition: all 0.15s;
  }
  .ope-drawer-close:hover {
    background: #F3F4F6;
    color: #374151;
  }

  .ope-drawer-content {
    flex: 1;
    overflow-y: auto;
    overflow-x: hidden;
  }

  @media (max-width: 768px) {
    .ope-drawer {
      right: 0;
      width: 100%;
      top: 48px;
      bottom: 56px;
    }
  }

  @media (min-width: 769px) and (max-width: 1024px) {
    .ope-drawer {
      width: 360px;
    }
  }
</style>
