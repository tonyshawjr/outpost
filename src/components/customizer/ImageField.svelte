<script>
  import MediaPicker from '$components/MediaPicker.svelte';

  let { key, label, value = '', onchange = () => {} } = $props();

  let showPicker = $state(false);

  function handleSelect(media) {
    onchange(key, media.path);
    showPicker = false;
  }

  function handleClear() {
    onchange(key, '');
  }
</script>

<div class="image-field">
  <div class="image-field-label">{label}</div>
  <div class="image-field-controls">
    {#if value}
      <div class="image-preview">
        <img src={value} alt={label} />
        <div class="image-actions">
          <button class="btn btn-secondary btn-sm" onclick={() => { showPicker = true; }}>Change</button>
          <button class="image-remove" onclick={handleClear}>Remove</button>
        </div>
      </div>
    {:else}
      <button class="image-choose" onclick={() => { showPicker = true; }}>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
        <span>Choose {label.toLowerCase()}</span>
      </button>
    {/if}
  </div>
</div>

{#if showPicker}
  <MediaPicker
    onselect={handleSelect}
    onclose={() => { showPicker = false; }}
  />
{/if}

<style>
  .image-field {
    padding: 10px 0;
    border-bottom: 1px solid var(--border-color);
  }

  .image-field:last-child {
    border-bottom: none;
  }

  .image-field-label {
    font-size: var(--font-size-sm);
    color: var(--text-primary);
    font-weight: 500;
    margin-bottom: 8px;
  }

  .image-preview {
    display: flex;
    align-items: center;
    gap: 12px;
  }

  .image-preview img {
    width: 64px;
    height: 64px;
    object-fit: contain;
    border-radius: var(--radius-md);
    background: var(--bg-secondary);
    border: 1px solid var(--border-color);
  }

  .image-actions {
    display: flex;
    align-items: center;
    gap: 8px;
  }

  .image-remove {
    font-size: var(--font-size-xs);
    color: var(--text-tertiary);
    background: none;
    border: none;
    cursor: pointer;
    padding: 0;
    text-decoration: underline;
    transition: color 0.15s;
  }

  .image-remove:hover {
    color: var(--color-danger);
  }

  .image-choose {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 12px 16px;
    background: var(--bg-secondary);
    border: 1px dashed var(--border-color);
    border-radius: var(--radius-md);
    color: var(--text-secondary);
    cursor: pointer;
    font-size: var(--font-size-sm);
    width: 100%;
    transition: border-color 0.15s, color 0.15s;
  }

  .image-choose:hover {
    border-color: var(--accent);
    color: var(--accent);
  }

  .image-choose svg {
    width: 18px;
    height: 18px;
    flex-shrink: 0;
  }
</style>
