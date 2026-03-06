<script>
  import { onMount } from 'svelte';
  import { media as mediaApi } from '$lib/api.js';
  import { mediaList, addToast } from '$lib/stores.js';
  import { humanFileSize, formatDate } from '$lib/utils.js';

  let loading = $state(true);
  let uploading = $state(false);
  let dragover = $state(false);
  let selected = $state(null);

  // Search, filter & sort
  let search = $state('');
  let typeFilter = $state('all');
  let sortBy = $state('newest');

  const docMimes = ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'text/csv', 'text/plain'];

  function getTypeGroup(mime) {
    if (mime.startsWith('image/')) return 'image';
    if (mime.startsWith('video/')) return 'video';
    if (docMimes.includes(mime)) return 'document';
    return 'other';
  }

  let items = $derived.by(() => {
    let list = $mediaList || [];

    // Text search
    if (search) {
      const q = search.toLowerCase();
      list = list.filter(i => i.original_name.toLowerCase().includes(q));
    }

    // Type filter
    if (typeFilter !== 'all') {
      list = list.filter(i => getTypeGroup(i.mime_type) === typeFilter);
    }

    // Sort
    switch (sortBy) {
      case 'oldest':
        list = [...list].sort((a, b) => a.uploaded_at.localeCompare(b.uploaded_at));
        break;
      case 'newest':
        list = [...list].sort((a, b) => b.uploaded_at.localeCompare(a.uploaded_at));
        break;
      case 'name':
        list = [...list].sort((a, b) => a.original_name.localeCompare(b.original_name));
        break;
      case 'name-desc':
        list = [...list].sort((a, b) => b.original_name.localeCompare(a.original_name));
        break;
      case 'largest':
        list = [...list].sort((a, b) => b.file_size - a.file_size);
        break;
      case 'smallest':
        list = [...list].sort((a, b) => a.file_size - b.file_size);
        break;
    }

    return list;
  });

  let totalCount = $derived(($mediaList || []).length);
  let isFiltered = $derived(search !== '' || typeFilter !== 'all');

  function clearFilters() {
    search = '';
    typeFilter = 'all';
    sortBy = 'newest';
  }

  onMount(() => {
    loadMedia();
  });

  async function loadMedia() {
    loading = true;
    try {
      const data = await mediaApi.list();
      mediaList.set(data.media || []);
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      loading = false;
    }
  }

  async function uploadFiles(files) {
    uploading = true;
    let count = 0;
    try {
      for (const file of files) {
        const data = await mediaApi.upload(file);
        if (data.media) {
          mediaList.update((m) => [data.media, ...m]);
          count++;
        }
      }
      addToast(`${count} file${count > 1 ? 's' : ''} uploaded`, 'success');
    } catch (err) {
      addToast('Upload failed: ' + err.message, 'error');
    } finally {
      uploading = false;
    }
  }

  function handleFileInput(e) {
    const files = e.target.files;
    if (files?.length) uploadFiles(Array.from(files));
  }

  function handleDrop(e) {
    e.preventDefault();
    dragover = false;
    const files = e.dataTransfer?.files;
    if (files?.length) uploadFiles(Array.from(files));
  }

  function handleDragOver(e) {
    e.preventDefault();
    dragover = true;
  }

  function handleDragLeave() {
    dragover = false;
  }

  async function deleteItem(item) {
    if (!confirm(`Delete "${item.original_name}"?`)) return;
    try {
      await mediaApi.delete(item.id);
      mediaList.update((m) => m.filter((i) => i.id !== item.id));
      if (selected?.id === item.id) selected = null;
      addToast('File deleted', 'success');
    } catch (err) {
      addToast(err.message, 'error');
    }
  }

  function copyPath(item) {
    navigator.clipboard.writeText(item.path);
    addToast('Path copied to clipboard', 'success');
  }

  // ── Alt text editing ──
  let altText = $state('');
  let savingAlt = $state(false);

  // Sync altText when selection changes
  $effect(() => {
    if (selected) {
      altText = selected.alt_text || '';
    }
  });

  async function saveAltText() {
    if (!selected) return;
    savingAlt = true;
    try {
      const data = await mediaApi.update(selected.id, { alt_text: altText });
      if (data.media) {
        mediaList.update(m => m.map(i => i.id === data.media.id ? data.media : i));
        selected = data.media;
      }
      addToast('Alt text saved', 'success');
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      savingAlt = false;
    }
  }

  // ── Resize ──
  let resizeW = $state(0);
  let resizeH = $state(0);
  let resizeLock = $state(true);
  let resizing = $state(false);

  $effect(() => {
    if (selected && selected.width) {
      resizeW = selected.width;
      resizeH = selected.height;
    }
  });

  function onResizeW(e) {
    const w = parseInt(e.target.value) || 0;
    resizeW = w;
    if (resizeLock && selected?.width && w > 0) {
      resizeH = Math.round(selected.height * (w / selected.width));
    }
  }

  function onResizeH(e) {
    const h = parseInt(e.target.value) || 0;
    resizeH = h;
    if (resizeLock && selected?.height && h > 0) {
      resizeW = Math.round(selected.width * (h / selected.height));
    }
  }

  async function applyResize() {
    if (!selected || resizeW < 1 || resizeH < 1) return;
    if (resizeW === selected.width && resizeH === selected.height) return;
    resizing = true;
    try {
      const data = await mediaApi.transform({ id: selected.id, action: 'resize', width: resizeW, height: resizeH });
      if (data.media) {
        mediaList.update(m => m.map(i => i.id === data.media.id ? data.media : i));
        selected = data.media;
      }
      addToast('Image resized', 'success');
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      resizing = false;
    }
  }

  // ── Crop ──
  let cropX = $state(0);
  let cropY = $state(0);
  let cropW = $state(0);
  let cropH = $state(0);
  let showCrop = $state(false);
  let cropping = $state(false);

  function initCrop() {
    if (!selected) return;
    cropX = 0;
    cropY = 0;
    cropW = selected.width;
    cropH = selected.height;
    showCrop = true;
  }

  async function applyCrop() {
    if (!selected || cropW < 1 || cropH < 1) return;
    cropping = true;
    try {
      const data = await mediaApi.transform({ id: selected.id, action: 'crop', x: cropX, y: cropY, width: cropW, height: cropH });
      if (data.media) {
        mediaList.update(m => m.map(i => i.id === data.media.id ? data.media : i));
        selected = data.media;
      }
      showCrop = false;
      addToast('Image cropped', 'success');
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      cropping = false;
    }
  }

  let isRasterImage = $derived(selected && selected.mime_type?.startsWith('image/') && selected.mime_type !== 'image/svg+xml' && selected.width > 0);
</script>

<div class="media-page">
  <!-- Page Header -->
  <div class="page-header">
    <div class="page-header-icon warning">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
    </div>
    <div class="page-header-content">
      <h1 class="page-title">Media Library</h1>
      <p class="page-subtitle">Upload and manage your media files</p>
    </div>
    <div class="page-header-actions">
      <label class="btn btn-primary" style="cursor: pointer;">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
        Upload
        <input type="file" multiple onchange={handleFileInput} hidden />
      </label>
    </div>
  </div>

  <!-- Drop zone -->
  <div
    class="drop-zone"
    class:dragover
    ondrop={handleDrop}
    ondragover={handleDragOver}
    ondragleave={handleDragLeave}
    style="margin-bottom: var(--space-xl);"
  >
    <div class="drop-zone-text">
      {#if uploading}
        <div class="spinner" style="margin: 0 auto var(--space-sm);"></div>
        Uploading...
      {:else}
        <strong>Drop files here</strong> or click Upload above
      {/if}
    </div>
  </div>

  {#if loading}
    <div class="loading-overlay">
      <div class="spinner"></div>
    </div>
  {:else if totalCount === 0}
    <div class="card">
      <div class="empty-state">
        <div class="empty-state-icon">&#128247;</div>
        <div class="empty-state-title">No media uploaded</div>
        <p style="font-size: var(--font-size-sm);">
          Drag and drop files or use the Upload button
        </p>
      </div>
    </div>
  {:else}
    <!-- Toolbar -->
    <div class="media-toolbar">
      <div class="media-search">
        <svg class="media-search-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <input
          type="text"
          class="media-search-input"
          placeholder="Search files..."
          bind:value={search}
        />
        {#if search}
          <button class="media-search-clear" onclick={() => search = ''}>
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
          </button>
        {/if}
      </div>

      <div class="media-type-chips">
        {#each [['all', 'All'], ['image', 'Images'], ['video', 'Videos'], ['document', 'Docs']] as [value, label]}
          <button
            class="media-chip"
            class:active={typeFilter === value}
            onclick={() => typeFilter = value}
          >{label}</button>
        {/each}
      </div>

      <select class="media-sort" bind:value={sortBy}>
        <option value="newest">Newest</option>
        <option value="oldest">Oldest</option>
        <option value="name">Name A–Z</option>
        <option value="name-desc">Name Z–A</option>
        <option value="largest">Largest</option>
        <option value="smallest">Smallest</option>
      </select>

      <span class="media-count">
        {#if isFiltered}{items.length} of {/if}{totalCount} file{totalCount !== 1 ? 's' : ''}
      </span>
    </div>

    {#if items.length === 0}
      <div class="media-empty-filter">
        <p>No files match your search</p>
        <button class="media-clear-link" onclick={clearFilters}>Clear filters</button>
      </div>
    {:else}
    <div class="media-layout">
      <div class="media-layout-main">
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
              {#if item.mime_type.startsWith('image/')}
                <img
                  src={item.thumb_path || item.path}
                  alt={item.alt_text || item.original_name}
                  loading="lazy"
                />
              {:else}
                <div style="display: flex; align-items: center; justify-content: center; height: 100%; color: var(--text-tertiary); font-size: var(--font-size-xs);">
                  {item.mime_type.split('/')[1]?.toUpperCase()}
                </div>
              {/if}
              <div class="media-item-name">{item.original_name}</div>
            </div>
          {/each}
        </div>
      </div>

      {#if selected}
        <div class="media-sidebar">
          <h3 class="media-sidebar-title">Details</h3>
          {#if selected.mime_type.startsWith('image/')}
            <img
              src={selected.path}
              alt={selected.alt_text || selected.original_name}
              class="media-sidebar-preview"
            />
          {/if}
          <div class="media-sidebar-meta">
            <div><strong>Name:</strong> {selected.original_name}</div>
            <div><strong>Size:</strong> {humanFileSize(selected.file_size)}</div>
            {#if selected.width}
              <div><strong>Dimensions:</strong> {selected.width} &times; {selected.height}</div>
            {/if}
            <div><strong>Type:</strong> {selected.mime_type}</div>
            <div><strong>Uploaded:</strong> {formatDate(selected.uploaded_at)}</div>
          </div>

          <!-- Alt text -->
          <div class="media-sidebar-section">
            <label class="media-sidebar-label">Alt text</label>
            <div class="media-alt-row">
              <input
                type="text"
                class="media-alt-input"
                bind:value={altText}
                placeholder="Describe the image..."
                onkeydown={(e) => e.key === 'Enter' && saveAltText()}
              />
              <button class="btn btn-secondary btn-sm" onclick={saveAltText} disabled={savingAlt}>
                {savingAlt ? '...' : 'Save'}
              </button>
            </div>
          </div>

          <!-- Resize (images only) -->
          {#if isRasterImage}
            <div class="media-sidebar-section">
              <label class="media-sidebar-label">Resize</label>
              <div class="media-resize-row">
                <input type="number" class="media-dim-input" value={resizeW} oninput={onResizeW} min="1" max="4800" />
                <button
                  class="media-lock-btn"
                  class:active={resizeLock}
                  onclick={() => resizeLock = !resizeLock}
                  title={resizeLock ? 'Unlock aspect ratio' : 'Lock aspect ratio'}
                >
                  {#if resizeLock}
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                  {:else}
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 9.9-1"/></svg>
                  {/if}
                </button>
                <input type="number" class="media-dim-input" value={resizeH} oninput={onResizeH} min="1" max="4800" />
              </div>
              <button
                class="btn btn-secondary btn-sm"
                style="width: 100%; margin-top: var(--space-xs);"
                onclick={applyResize}
                disabled={resizing || (resizeW === selected.width && resizeH === selected.height)}
              >
                {resizing ? 'Resizing...' : 'Apply Resize'}
              </button>
            </div>

            <!-- Crop -->
            <div class="media-sidebar-section">
              <label class="media-sidebar-label">Crop</label>
              {#if showCrop}
                <div class="media-crop-inputs">
                  <div class="media-crop-grid">
                    <label class="media-crop-field">
                      <span>X</span>
                      <input type="number" bind:value={cropX} min="0" max={selected.width - 1} />
                    </label>
                    <label class="media-crop-field">
                      <span>Y</span>
                      <input type="number" bind:value={cropY} min="0" max={selected.height - 1} />
                    </label>
                    <label class="media-crop-field">
                      <span>W</span>
                      <input type="number" bind:value={cropW} min="1" max={selected.width - cropX} />
                    </label>
                    <label class="media-crop-field">
                      <span>H</span>
                      <input type="number" bind:value={cropH} min="1" max={selected.height - cropY} />
                    </label>
                  </div>
                  <div style="display: flex; gap: var(--space-xs); margin-top: var(--space-xs);">
                    <button class="btn btn-primary btn-sm" style="flex: 1;" onclick={applyCrop} disabled={cropping}>
                      {cropping ? 'Cropping...' : 'Apply Crop'}
                    </button>
                    <button class="btn btn-secondary btn-sm" onclick={() => showCrop = false}>Cancel</button>
                  </div>
                </div>
              {:else}
                <button class="btn btn-secondary btn-sm" style="width: 100%;" onclick={initCrop}>
                  Crop Image
                </button>
              {/if}
            </div>
          {/if}

          <div style="display: flex; gap: var(--space-xs); margin-top: var(--space-md);">
            <button class="btn btn-secondary btn-sm" onclick={() => copyPath(selected)} style="flex: 1;">Copy Path</button>
            <button class="btn btn-danger btn-sm" onclick={() => deleteItem(selected)}>Delete</button>
          </div>
        </div>
      {/if}
    </div>
    {/if}
  {/if}
</div>

<style>
  .media-page {
    max-width: var(--content-width-wide);
  }

  /* Toolbar */
  .media-toolbar {
    display: flex;
    align-items: center;
    gap: var(--space-md);
    margin-bottom: var(--space-lg);
  }

  /* Search */
  .media-search {
    position: relative;
    flex: 1;
    max-width: 280px;
  }
  .media-search-icon {
    position: absolute;
    left: 0;
    top: 50%;
    transform: translateY(-50%);
    color: var(--text-tertiary);
    pointer-events: none;
  }
  .media-search-input {
    width: 100%;
    padding: 6px 24px 6px 20px;
    border: 1px solid transparent;
    border-radius: var(--radius-md);
    background: transparent;
    font-size: var(--font-size-sm);
    color: var(--text-primary);
    outline: none;
    transition: border-color 0.15s;
  }
  .media-search-input:hover,
  .media-search-input:focus {
    border-color: var(--border-color);
  }
  .media-search-input::placeholder {
    color: var(--text-tertiary);
  }
  .media-search-clear {
    position: absolute;
    right: 4px;
    top: 50%;
    transform: translateY(-50%);
    background: none;
    border: none;
    padding: 2px;
    cursor: pointer;
    color: var(--text-tertiary);
    display: flex;
    align-items: center;
  }
  .media-search-clear:hover {
    color: var(--text-primary);
  }

  /* Type chips */
  .media-type-chips {
    display: flex;
    gap: var(--space-xs);
  }
  .media-chip {
    background: none;
    border: none;
    padding: 4px 8px;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--text-tertiary);
    cursor: pointer;
    border-radius: var(--radius-sm);
    transition: color 0.15s;
  }
  .media-chip:hover {
    color: var(--text-primary);
  }
  .media-chip.active {
    color: var(--text-primary);
    font-weight: 600;
  }

  /* Sort */
  .media-sort {
    padding: 4px 8px;
    border: 1px solid transparent;
    border-radius: var(--radius-md);
    background: transparent;
    font-size: var(--font-size-xs);
    color: var(--text-secondary);
    cursor: pointer;
    outline: none;
    transition: border-color 0.15s;
  }
  .media-sort:hover,
  .media-sort:focus {
    border-color: var(--border-color);
  }

  /* Count */
  .media-count {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--text-tertiary);
    white-space: nowrap;
    margin-left: auto;
  }

  /* Empty filter state */
  .media-empty-filter {
    text-align: center;
    padding: var(--space-3xl) 0;
    color: var(--text-tertiary);
  }
  .media-empty-filter p {
    font-size: var(--font-size-sm);
    margin-bottom: var(--space-sm);
  }
  .media-clear-link {
    background: none;
    border: none;
    color: var(--text-secondary);
    text-decoration: underline;
    cursor: pointer;
    font-size: var(--font-size-sm);
    padding: 0;
  }
  .media-clear-link:hover {
    color: var(--text-primary);
  }

  /* Sidebar */
  .media-sidebar {
    width: 280px;
    flex-shrink: 0;
    align-self: flex-start;
    background: var(--bg-secondary);
    border-radius: var(--radius-lg);
    padding: var(--space-lg);
  }
  .media-sidebar-title {
    font-size: var(--font-size-sm);
    font-weight: 600;
    margin-bottom: var(--space-md);
  }
  .media-sidebar-preview {
    width: 100%;
    border-radius: var(--radius-md);
    margin-bottom: var(--space-md);
  }
  .media-sidebar-meta {
    font-size: var(--font-size-xs);
    color: var(--text-secondary);
    display: flex;
    flex-direction: column;
    gap: var(--space-xs);
  }
  .media-sidebar-section {
    margin-top: var(--space-md);
    padding-top: var(--space-md);
    border-top: 1px solid var(--border-color);
  }
  .media-sidebar-label {
    display: block;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--text-tertiary);
    margin-bottom: var(--space-xs);
  }

  /* Alt text */
  .media-alt-row {
    display: flex;
    gap: var(--space-xs);
  }
  .media-alt-input {
    flex: 1;
    padding: 5px 8px;
    border: 1px solid transparent;
    border-radius: var(--radius-md);
    background: transparent;
    font-size: var(--font-size-xs);
    color: var(--text-primary);
    outline: none;
    transition: border-color 0.15s;
  }
  .media-alt-input:hover,
  .media-alt-input:focus {
    border-color: var(--border-color);
  }

  /* Resize */
  .media-resize-row {
    display: flex;
    align-items: center;
    gap: var(--space-xs);
  }
  .media-dim-input {
    flex: 1;
    width: 0;
    padding: 5px 8px;
    border: 1px solid transparent;
    border-radius: var(--radius-md);
    background: transparent;
    font-size: var(--font-size-xs);
    color: var(--text-primary);
    outline: none;
    transition: border-color 0.15s;
    text-align: center;
  }
  .media-dim-input:hover,
  .media-dim-input:focus {
    border-color: var(--border-color);
  }
  .media-lock-btn {
    background: none;
    border: none;
    padding: 4px;
    cursor: pointer;
    color: var(--text-tertiary);
    border-radius: var(--radius-sm);
    display: flex;
    align-items: center;
    transition: color 0.15s;
  }
  .media-lock-btn:hover {
    color: var(--text-primary);
  }
  .media-lock-btn.active {
    color: var(--text-secondary);
  }

  /* Crop */
  .media-crop-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: var(--space-xs);
  }
  .media-crop-field {
    display: flex;
    align-items: center;
    gap: 4px;
    font-size: var(--font-size-xs);
    color: var(--text-tertiary);
  }
  .media-crop-field input {
    flex: 1;
    width: 0;
    padding: 4px 6px;
    border: 1px solid transparent;
    border-radius: var(--radius-sm);
    background: transparent;
    font-size: var(--font-size-xs);
    color: var(--text-primary);
    outline: none;
    text-align: center;
    transition: border-color 0.15s;
  }
  .media-crop-field input:hover,
  .media-crop-field input:focus {
    border-color: var(--border-color);
  }

  /* Layout: grid + sidebar */
  .media-layout {
    display: flex;
    gap: var(--space-xl);
  }
  .media-layout-main {
    flex: 1;
    min-width: 0;
  }

  /* ── Mobile ── */
  @media (max-width: 768px) {
    .media-layout {
      flex-direction: column;
    }

    .media-sidebar {
      width: 100%;
      order: -1;
    }

    .media-toolbar {
      flex-wrap: wrap;
      gap: var(--space-sm);
    }

    .media-search {
      flex: none;
      width: 100%;
      max-width: none;
    }

    .media-type-chips {
      flex-wrap: wrap;
    }

    .media-resize-row {
      flex-wrap: wrap;
    }

    .media-crop-grid {
      grid-template-columns: 1fr;
    }
  }
</style>
