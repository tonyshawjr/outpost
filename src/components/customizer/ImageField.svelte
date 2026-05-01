<script>
  let { key = '', label = '', value = '', onchange = () => {} } = $props();

  let dragOver = $state(false);

  function openMediaPicker() {
    // Use native file picker as simple fallback
    const input = document.createElement('input');
    input.type = 'file';
    input.accept = 'image/*';
    input.onchange = (e) => {
      const file = e.target.files?.[0];
      if (file) {
        // Create object URL for preview; actual upload handled by parent
        const url = URL.createObjectURL(file);
        onchange(key, url);
      }
    };
    input.click();
  }

  function clearImage() {
    onchange(key, '');
  }

  function handlePaste(text) {
    if (text && (text.startsWith('/') || text.startsWith('http'))) {
      onchange(key, text.trim());
    }
  }
</script>

<div class="image-field">
  <label class="image-label">{label}</label>
  {#if value}
    <div class="image-preview-wrap">
      <img src={value} alt={label} class="image-preview" />
      <div class="image-actions">
        <button class="btn btn-ghost btn-sm" type="button" onclick={openMediaPicker}>Change</button>
        <button class="btn btn-ghost btn-sm" type="button" onclick={clearImage} style="color: var(--danger, #dc3545);">Remove</button>
      </div>
    </div>
  {:else}
    <button
      class="image-upload-btn"
      type="button"
      onclick={openMediaPicker}
      class:drag-over={dragOver}
      ondragover={(e) => { e.preventDefault(); dragOver = true; }}
      ondragleave={() => { dragOver = false; }}
      ondrop={(e) => {
        e.preventDefault();
        dragOver = false;
        const text = e.dataTransfer?.getData('text/plain');
        if (text) handlePaste(text);
      }}
    >
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
        <rect x="3" y="3" width="18" height="18" rx="2" ry="2"/>
        <circle cx="8.5" cy="8.5" r="1.5"/>
        <polyline points="21 15 16 10 5 21"/>
      </svg>
      <span>Upload or drag image</span>
    </button>
  {/if}
</div>

<style>
  .image-field { margin-bottom: var(--space-lg, 16px); }
  .image-label {
    display: block;
    font-size: 12px;
    font-weight: 500;
    color: var(--sec);
    margin-bottom: var(--space-xs, 4px);
    text-transform: uppercase;
    letter-spacing: 0.04em;
  }
  .image-preview-wrap {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
    padding: 12px;
    background: var(--bg-secondary, #f5f5f5);
    border-radius: var(--radius-sm, 6px);
    border: 1px solid var(--border-primary, #e5e5e5);
  }
  .image-preview {
    max-width: 100%;
    max-height: 120px;
    object-fit: contain;
    border-radius: 4px;
  }
  .image-actions {
    display: flex;
    gap: 8px;
  }
  .image-upload-btn {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 8px;
    width: 100%;
    padding: 24px;
    background: var(--bg-secondary, #f5f5f5);
    border: 2px dashed var(--border-primary, #ddd);
    border-radius: var(--radius-sm, 6px);
    color: var(--dim);
    cursor: pointer;
    font-size: 13px;
    transition: border-color 0.15s, background 0.15s;
  }
  .image-upload-btn:hover, .image-upload-btn.drag-over {
    border-color: var(--accent, #1a73e8);
    background: var(--accent-bg, #f0f6ff);
  }
</style>
