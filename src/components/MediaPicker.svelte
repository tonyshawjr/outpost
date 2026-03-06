<script>
  import { onMount } from 'svelte';
  import { media as mediaApi } from '$lib/api.js';
  import { addToast } from '$lib/stores.js';
  import { humanFileSize } from '$lib/utils.js';

  let {
    onselect = () => {},
    onclose = () => {},
  } = $props();

  let items = $state([]);
  let loading = $state(true);
  let uploading = $state(false);
  let selected = $state(null);

  onMount(async () => {
    await loadMedia();
  });

  async function loadMedia() {
    loading = true;
    try {
      const data = await mediaApi.list();
      items = (data.media || []).filter((m) =>
        m.mime_type.startsWith('image/')
      );
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      loading = false;
    }
  }

  async function handleUpload(e) {
    const files = e.target.files;
    if (!files?.length) return;

    uploading = true;
    try {
      for (const file of files) {
        const data = await mediaApi.upload(file);
        if (data.media) {
          items = [data.media, ...items];
        }
      }
      addToast('Upload complete', 'success');
    } catch (err) {
      addToast('Upload failed: ' + err.message, 'error');
    } finally {
      uploading = false;
    }
  }

  function confirmSelect() {
    if (selected) {
      onselect(selected);
    }
  }
</script>

<div class="modal-overlay" onclick={onclose} role="dialog" aria-modal="true">
  <div class="modal modal-lg" onclick={(e) => e.stopPropagation()} role="document">
    <div class="modal-header">
      <h2 class="modal-title">Select Image</h2>
      <button class="btn btn-ghost btn-sm" onclick={onclose}>
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>

    <div style="margin-bottom: var(--space-lg);">
      <label class="btn btn-secondary btn-sm" style="cursor: pointer;">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
        {uploading ? 'Uploading...' : 'Upload'}
        <input type="file" accept="image/*" multiple onchange={handleUpload} hidden />
      </label>
    </div>

    {#if loading}
      <div class="loading-overlay">
        <div class="spinner"></div>
      </div>
    {:else if items.length === 0}
      <div class="empty-state">
        <div class="empty-state-title">No images</div>
        <p style="font-size: var(--font-size-sm);">Upload an image to get started.</p>
      </div>
    {:else}
      <div class="media-grid">
        {#each items as item (item.id)}
          <div
            class="media-item"
            class:selected={selected?.id === item.id}
            onclick={() => selected = item}
            role="button"
            tabindex="0"
            onkeydown={(e) => e.key === 'Enter' && (selected = item)}
          >
            <img
              src={item.thumb_path || item.path}
              alt={item.alt_text || item.original_name}
              loading="lazy"
            />
            <div class="media-item-name">{item.original_name}</div>
          </div>
        {/each}
      </div>
    {/if}

    <div class="modal-footer">
      <button class="btn btn-secondary" onclick={onclose}>Cancel</button>
      <button class="btn btn-primary" onclick={confirmSelect} disabled={!selected}>
        Select Image
      </button>
    </div>
  </div>
</div>
