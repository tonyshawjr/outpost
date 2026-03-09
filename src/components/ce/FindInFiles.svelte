<script>
  import { code as codeApi } from '$lib/api.js';

  let { visible = false, onClose, onOpenFile } = $props();

  let query     = $state('');
  let results   = $state([]);
  let searching = $state(false);
  let searched  = $state(false);
  let inputEl   = $state(null);

  // Group results by file
  let grouped = $derived.by(() => {
    const map = {};
    for (const r of results) {
      if (!map[r.path]) map[r.path] = [];
      map[r.path].push(r);
    }
    return Object.entries(map);
  });

  $effect(() => {
    if (visible && inputEl) {
      inputEl.focus();
    }
  });

  async function doSearch() {
    if (!query.trim() || query.length < 2) return;
    searching = true;
    searched = false;
    try {
      const data = await codeApi.search(query.trim());
      results = data.results || [];
      searched = true;
    } catch (e) {
      results = [];
    } finally {
      searching = false;
    }
  }

  function handleKey(e) {
    if (e.key === 'Enter') doSearch();
    if (e.key === 'Escape') onClose();
  }

  function openResult(r) {
    onOpenFile({ path: r.path, name: r.path.split('/').pop(), type: 'file' }, r.line);
  }

  function escHtml(s) {
    return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  function highlight(text, q) {
    if (!q) return escHtml(text);
    const idx = text.toLowerCase().indexOf(q.toLowerCase());
    if (idx < 0) return escHtml(text);
    return escHtml(text.slice(0, idx)) + '<mark>' + escHtml(text.slice(idx, idx + q.length)) + '</mark>' + escHtml(text.slice(idx + q.length));
  }
</script>

{#if visible}
  <div class="fif-panel">
    <div class="fif-header">
      <span class="fif-title">Find in Files</span>
      <button class="fif-close" onclick={onClose} title="Close (Esc)">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>

    <div class="fif-search-row">
      <input
        class="fif-input"
        bind:this={inputEl}
        bind:value={query}
        onkeydown={handleKey}
        placeholder="Search across all theme files…"
        autocomplete="off"
      />
      <button class="fif-btn" onclick={doSearch} disabled={searching}>
        {searching ? '…' : 'Search'}
      </button>
    </div>

    <div class="fif-results">
      {#if searching}
        <div class="fif-status">Searching…</div>
      {:else if searched && results.length === 0}
        <div class="fif-status">No results for "{query}"</div>
      {:else if searched}
        <div class="fif-count">{results.length} result{results.length !== 1 ? 's' : ''} in {grouped.length} file{grouped.length !== 1 ? 's' : ''}</div>
        {#each grouped as [path, matches]}
          <div class="fif-file-group">
            <div class="fif-file-name">{path}</div>
            {#each matches as r}
              <button class="fif-match" onclick={() => openResult(r)}>
                <span class="fif-line-num">{r.line}</span>
                <span class="fif-preview">{@html highlight(r.preview, query)}</span>
              </button>
            {/each}
          </div>
        {/each}
      {:else}
        <div class="fif-status fif-hint">Press Enter or click Search</div>
      {/if}
    </div>
  </div>
{/if}

<style>
  .fif-panel {
    height: 240px;
    flex-shrink: 0;
    display: flex;
    flex-direction: column;
    border-top: 1px solid var(--ce-bar-border);
    background: var(--ce-bg);
  }

  .fif-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    height: 36px;
    padding: 0 16px;
    border-bottom: 1px solid var(--ce-bar-border);
    flex-shrink: 0;
  }

  .fif-title {
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .06em;
    color: var(--ce-muted);
  }

  .fif-close {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 24px;
    height: 24px;
    background: none;
    border: none;
    border-radius: 4px;
    color: var(--ce-muted);
    cursor: pointer;
  }
  .fif-close:hover { background: var(--ce-btn-hover-bg); color: var(--ce-text); }

  .fif-search-row {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 16px;
    flex-shrink: 0;
  }

  .fif-input {
    flex: 1;
    height: 30px;
    padding: 0 10px;
    font-size: 13px;
    font-family: var(--font-sans);
    color: var(--ce-text);
    background: var(--ce-btn-hover-bg);
    border: 1px solid var(--ce-bar-border);
    border-radius: 6px;
    outline: none;
    transition: border-color var(--transition-fast);
  }
  .fif-input:focus { border-color: var(--forest); }

  .fif-btn {
    height: 30px;
    padding: 0 14px;
    background: none;
    border: 1px solid var(--ce-bar-border);
    border-radius: 6px;
    font-size: 12px;
    font-family: var(--font-sans);
    color: var(--ce-filepath);
    cursor: pointer;
    white-space: nowrap;
    transition: background var(--transition-fast), color var(--transition-fast);
  }
  .fif-btn:hover:not(:disabled) { background: var(--ce-btn-hover-bg); color: var(--ce-text); }
  .fif-btn:disabled { opacity: .5; cursor: default; }

  .fif-results {
    flex: 1;
    overflow-y: auto;
    min-height: 0;
  }

  .fif-status {
    padding: 16px;
    font-size: 12px;
    color: var(--ce-muted);
  }
  .fif-hint { color: var(--ce-muted); opacity: .6; }

  .fif-count {
    padding: 6px 16px;
    font-size: 11px;
    color: var(--ce-muted);
    border-bottom: 1px solid var(--ce-bar-border);
  }

  .fif-file-group {
    border-bottom: 1px solid var(--ce-bar-border);
  }

  .fif-file-name {
    padding: 6px 16px 2px;
    font-size: 11px;
    font-family: var(--font-mono);
    font-weight: 600;
    color: var(--ce-filepath);
  }

  .fif-match {
    display: flex;
    align-items: baseline;
    gap: 10px;
    width: 100%;
    padding: 3px 16px 3px 20px;
    background: none;
    border: none;
    font-family: var(--font-mono);
    font-size: 11.5px;
    color: var(--ce-text);
    text-align: left;
    cursor: pointer;
    transition: background var(--transition-fast);
  }
  .fif-match:hover { background: var(--ce-btn-hover-bg); }

  .fif-line-num {
    color: var(--ce-muted);
    min-width: 32px;
    flex-shrink: 0;
    font-size: 11px;
  }

  .fif-preview {
    flex: 1;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .fif-preview :global(mark) {
    background: rgba(245, 158, 11, 0.3);
    color: inherit;
    border-radius: 2px;
  }
</style>
