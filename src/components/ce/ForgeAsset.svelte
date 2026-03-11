<script>
  import { code as codeApi } from '$lib/api.js';
  import { addToast } from '$lib/stores.js';

  let { visible = false, themeSlug = '', onInsert = () => {}, onClose = () => {} } = $props();

  let assets = $state([]);
  let loading = $state(false);
  let search = $state('');
  let lastSlug = $state('');

  $effect(() => {
    if (visible && themeSlug && themeSlug !== lastSlug) {
      lastSlug = themeSlug;
      loadAssets();
    }
  });

  async function loadAssets() {
    loading = true;
    try {
      const data = await codeApi.assets(themeSlug);
      assets = data.files || [];
    } catch (e) {
      addToast('Failed to load assets', 'error');
      assets = [];
    } finally {
      loading = false;
    }
  }

  let grouped = $derived.by(() => {
    const q = search.trim().toLowerCase();
    const items = q ? assets.filter(f => f.name.toLowerCase().includes(q) || f.path.toLowerCase().includes(q)) : assets;

    const css = [], js = [], images = [], other = [];
    for (const file of items) {
      const ext = file.name.split('.').pop().toLowerCase();
      if (ext === 'css') css.push(file);
      else if (ext === 'js') js.push(file);
      else if (['png','jpg','jpeg','gif','webp','svg','avif','ico'].includes(ext)) images.push(file);
      else other.push(file);
    }
    return { css, js, images, other };
  });

  function buildTag(file) {
    const ext = file.name.split('.').pop().toLowerCase();
    const href = `/outpost/content/themes/${themeSlug}/assets/${file.path}`;

    if (ext === 'css') return `<link rel="stylesheet" href="${href}">`;
    if (ext === 'js') return `<script src="${href}"></` + 'script>';
    if (['png','jpg','jpeg','gif','webp','svg','avif','ico'].includes(ext)) return `<img src="${href}" alt="">`;
    return href;
  }

  function handleInsert(file) {
    onInsert(buildTag(file));
  }

  function handleKeydown(e) {
    if (e.key === 'Escape') onClose();
  }
</script>

<svelte:window onkeydown={handleKeydown} />

{#if visible}
  <div class="fa-overlay" onclick={onClose}>
    <div class="fa-panel" onclick={(e) => e.stopPropagation()}>
      <div class="fa-header">
        <span class="fa-title">Theme Assets</span>
        <button class="fa-close" onclick={onClose}>
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
      </div>

      <div class="fa-search">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <input type="text" placeholder="Search assets..." bind:value={search} spellcheck="false" />
      </div>

      <div class="fa-body">
        {#if loading}
          <div class="fa-loading"><div class="spinner"></div></div>
        {:else if assets.length === 0}
          <p class="fa-empty">No assets found.<br>Add files to your theme's <code>assets/</code> folder.</p>
        {:else}
          {#if grouped.css.length > 0}
            <div class="fa-group">
              <div class="fa-group-label">Stylesheets</div>
              {#each grouped.css as file}
                <button class="fa-file-row" onclick={() => handleInsert(file)}>
                  <span class="fa-dot css"></span>
                  <span class="fa-file-name">{file.path}</span>
                  <span class="fa-insert">Insert</span>
                </button>
              {/each}
            </div>
          {/if}

          {#if grouped.js.length > 0}
            <div class="fa-group">
              <div class="fa-group-label">Scripts</div>
              {#each grouped.js as file}
                <button class="fa-file-row" onclick={() => handleInsert(file)}>
                  <span class="fa-dot js"></span>
                  <span class="fa-file-name">{file.path}</span>
                  <span class="fa-insert">Insert</span>
                </button>
              {/each}
            </div>
          {/if}

          {#if grouped.images.length > 0}
            <div class="fa-group">
              <div class="fa-group-label">Images</div>
              {#each grouped.images as file}
                <button class="fa-file-row" onclick={() => handleInsert(file)}>
                  <span class="fa-dot img"></span>
                  <span class="fa-file-name">{file.path}</span>
                  <span class="fa-insert">Insert</span>
                </button>
              {/each}
            </div>
          {/if}

          {#if grouped.other.length > 0}
            <div class="fa-group">
              <div class="fa-group-label">Other</div>
              {#each grouped.other as file}
                <button class="fa-file-row" onclick={() => handleInsert(file)}>
                  <span class="fa-dot"></span>
                  <span class="fa-file-name">{file.path}</span>
                  <span class="fa-insert">Insert</span>
                </button>
              {/each}
            </div>
          {/if}

          {#if grouped.css.length === 0 && grouped.js.length === 0 && grouped.images.length === 0 && grouped.other.length === 0}
            <p class="fa-empty">No assets match your search</p>
          {/if}
        {/if}
      </div>

      <div class="fa-footer">
        <span class="fa-footer-hint">Click to insert at cursor</span>
      </div>
    </div>
  </div>
{/if}

<style>
  .fa-overlay {
    position: fixed;
    inset: 0;
    z-index: 1000;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(0,0,0,.3);
    backdrop-filter: blur(2px);
  }

  .fa-panel {
    width: 420px;
    max-height: 520px;
    background: var(--bg-primary);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-xl, 0 20px 60px rgba(0,0,0,.2));
    display: flex;
    flex-direction: column;
    overflow: hidden;
  }

  .fa-header {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 12px 14px;
    border-bottom: 1px solid var(--border-light);
    flex-shrink: 0;
  }

  .fa-title {
    flex: 1;
    font-size: 13px;
    font-weight: 600;
    color: var(--text-primary);
  }

  .fa-close {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 24px;
    height: 24px;
    padding: 0;
    background: none;
    border: none;
    border-radius: var(--radius-sm);
    color: var(--text-tertiary);
    cursor: pointer;
    flex-shrink: 0;
  }

  .fa-close:hover {
    background: var(--bg-secondary);
    color: var(--text-primary);
  }

  .fa-search {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 14px;
    border-bottom: 1px solid var(--border-light);
    color: var(--text-tertiary);
    flex-shrink: 0;
  }

  .fa-search input {
    flex: 1;
    border: none;
    background: none;
    font-size: 13px;
    color: var(--text-primary);
    outline: none;
  }

  .fa-search input::placeholder {
    color: var(--text-tertiary);
  }

  .fa-body {
    flex: 1;
    overflow-y: auto;
    padding: 4px 0;
  }

  .fa-loading {
    display: flex;
    justify-content: center;
    padding: 32px;
  }

  .fa-empty {
    text-align: center;
    color: var(--text-tertiary);
    font-size: 13px;
    padding: 24px 14px;
    line-height: 1.5;
  }

  .fa-empty code {
    font-size: 12px;
    background: var(--bg-secondary);
    padding: 1px 5px;
    border-radius: 3px;
  }

  .fa-group {
    padding: 0;
  }

  .fa-group-label {
    font-size: 10px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .04em;
    color: var(--text-tertiary);
    padding: 10px 14px 4px;
  }

  .fa-file-row {
    display: flex;
    align-items: center;
    gap: 8px;
    width: 100%;
    padding: 8px 14px;
    background: none;
    border: none;
    cursor: pointer;
    text-align: left;
    color: var(--text-primary);
    transition: background 0.1s;
    font-family: var(--font-sans);
  }

  .fa-file-row:hover {
    background: var(--bg-secondary);
  }

  .fa-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    flex-shrink: 0;
    background: var(--text-tertiary);
  }

  .fa-dot.css { background: #3b82f6; }
  .fa-dot.js  { background: #eab308; }
  .fa-dot.img { background: #22c55e; }

  .fa-file-name {
    flex: 1;
    font-size: 13px;
    font-family: var(--font-mono, monospace);
  }

  .fa-insert {
    font-size: 11px;
    font-weight: 500;
    color: var(--accent);
    flex-shrink: 0;
    opacity: 0;
    transition: opacity 0.15s;
  }

  .fa-file-row:hover .fa-insert {
    opacity: 1;
  }

  .fa-footer {
    padding: 8px 14px;
    border-top: 1px solid var(--border-light);
    flex-shrink: 0;
  }

  .fa-footer-hint {
    font-size: 11px;
    color: var(--text-tertiary);
  }
</style>
