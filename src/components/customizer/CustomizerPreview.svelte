<script>
  let { values = {}, schema = null } = $props();

  let iframeRef = $state(null);
  let iframeReady = $state(false);
  let loading = $state(true);

  // Listen for preview-ready signal from iframe
  function handleMessage(e) {
    if (e.origin !== window.location.origin) return;
    if (e.data?.type === 'outpost-customizer-preview-ready') {
      iframeReady = true;
      loading = false;
      sendUpdate();
    }
  }

  $effect(() => {
    window.addEventListener('message', handleMessage);
    return () => window.removeEventListener('message', handleMessage);
  });

  // Send updated values to iframe whenever they change
  $effect(() => {
    if (values && schema) {
      sendUpdate();
    }
  });

  function sendUpdate() {
    if (!iframeRef || !iframeReady) return;
    try {
      iframeRef.contentWindow.postMessage({
        type: 'outpost-customizer-update',
        values: JSON.parse(JSON.stringify(values)),
        schema: JSON.parse(JSON.stringify(schema)),
      }, window.location.origin);
    } catch (e) {
      // Cross-origin errors are expected during initial load
    }
  }

  function handleIframeLoad() {
    // The preview-ready message will set iframeReady
    // But in case it takes too long, remove spinner after 3s
    setTimeout(() => { loading = false; }, 3000);
  }

  // Build preview URL — same origin, root path
  let previewUrl = $derived.by(() => {
    const base = window.location.origin;
    return base + '/?_outpost_customizer_preview=1';
  });
</script>

<div class="customizer-preview">
  {#if loading}
    <div class="preview-loading">
      <div class="spinner"></div>
    </div>
  {/if}
  <iframe
    bind:this={iframeRef}
    src={previewUrl}
    onload={handleIframeLoad}
    title="Theme Preview"
    class="preview-iframe"
    class:visible={!loading}
  ></iframe>
</div>

<style>
  .customizer-preview {
    width: 100%;
    height: 100%;
    position: relative;
    background: var(--bg-secondary);
  }

  .preview-iframe {
    width: 100%;
    height: 100%;
    border: none;
    opacity: 0;
    transition: opacity 0.3s;
  }

  .preview-iframe.visible {
    opacity: 1;
  }

  .preview-loading {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
  }
</style>
