<script>
  import { forge } from '$lib/api.js';
  import { addToast } from '$lib/stores.js';

  let { onClose } = $props();

  let phase = $state('loading'); // 'loading' | 'preview' | 'applying' | 'done'
  let analysis = $state(null);
  let error = $state(null);
  let applyResult = $state(null);

  // Per-file skip checkboxes: { [filename]: boolean }
  let skipped = $state({});

  // Collapsible sections
  let collapsed = $state({});

  function toggleCollapse(key) {
    collapsed = { ...collapsed, [key]: !collapsed[key] };
  }

  function toggleSkip(file) {
    skipped = { ...skipped, [file]: !skipped[file] };
  }

  // Summary counts
  let summary = $derived(() => {
    if (!analysis) return null;
    return {
      pages: analysis.pages?.length || 0,
      fields: analysis.pages?.reduce((sum, p) => sum + (p.fields?.length || 0), 0) || 0,
      partials: analysis.partials?.length || 0,
      menus: analysis.menus?.length || 0,
      globals: analysis.globals?.length || 0,
    };
  });

  async function runAnalysis() {
    try {
      const result = await forge.analyze();
      analysis = result;
      phase = 'preview';
    } catch (err) {
      error = err.message;
      phase = 'preview';
    }
  }

  async function applyAnalysis() {
    phase = 'applying';
    try {
      const skipFiles = Object.entries(skipped)
        .filter(([, v]) => v)
        .map(([k]) => k);
      const result = await forge.analyzeApply({ skip: skipFiles });
      applyResult = result;
      phase = 'done';
      addToast('Site analysis applied successfully', 'success');
    } catch (err) {
      addToast('Apply failed: ' + err.message, 'error');
      phase = 'preview';
    }
  }

  // Run analysis on mount
  $effect(() => {
    runAnalysis();
  });
</script>

<div class="fa-overlay" onclick={onClose}>
  <div class="fa-modal" onclick={(e) => e.stopPropagation()}>
    <!-- Header -->
    <div class="fa-header">
      <div>
        <h3 class="fa-title">
          {#if phase === 'loading'}
            Analyzing site...
          {:else if phase === 'applying'}
            Applying changes...
          {:else if phase === 'done'}
            Analysis applied
          {:else}
            Site Analysis
          {/if}
        </h3>
        {#if phase === 'preview' && !error && summary()}
          <p class="fa-subtitle">
            Found <strong>{summary().pages}</strong> {summary().pages === 1 ? 'page' : 'pages'},
            <strong>{summary().fields}</strong> {summary().fields === 1 ? 'field' : 'fields'},
            <strong>{summary().partials}</strong> {summary().partials === 1 ? 'partial' : 'partials'},
            <strong>{summary().menus}</strong> {summary().menus === 1 ? 'menu' : 'menus'},
            <strong>{summary().globals}</strong> {summary().globals === 1 ? 'global' : 'globals'}
          </p>
        {/if}
      </div>
      <button class="fa-close" onclick={onClose} aria-label="Close">
        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" width="16" height="16">
          <line x1="5" y1="5" x2="15" y2="15"/><line x1="15" y1="5" x2="5" y2="15"/>
        </svg>
      </button>
    </div>

    <!-- Body -->
    <div class="fa-body">
      {#if phase === 'loading' || phase === 'applying'}
        <div class="fa-spinner-wrap">
          <div class="fa-spinner"></div>
          <p class="fa-spinner-text">
            {phase === 'loading' ? 'Scanning all theme templates...' : 'Writing changes...'}
          </p>
        </div>
      {:else if error}
        <div class="fa-error">
          <p>{error}</p>
        </div>
      {:else if phase === 'done' && applyResult}
        <div class="fa-done">
          <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="var(--forest, #229672)" stroke-width="2">
            <path d="M22 11.08V12a10 10 0 11-5.93-9.14"/>
            <polyline points="22 4 12 14.01 9 11.01"/>
          </svg>
          <p class="fa-done-title">Analysis applied successfully</p>
          {#if applyResult.created_pages != null}
            <div class="fa-done-stats">
              <span>{applyResult.created_pages || 0} pages created</span>
              <span>{applyResult.created_fields || 0} fields registered</span>
              <span>{applyResult.created_menus || 0} menus created</span>
              <span>{applyResult.globals_registered || 0} globals registered</span>
              {#if applyResult.skipped_files?.length}
                <span>{applyResult.skipped_files.length} files skipped</span>
              {/if}
            </div>
          {/if}
        </div>
      {:else if analysis}
        <!-- Partials -->
        {#if analysis.partials?.length}
          <div class="fa-section">
            <button class="fa-section-header" onclick={() => toggleCollapse('partials')}>
              <span class="fa-section-label">Partials</span>
              <span class="fa-section-count">{analysis.partials.length}</span>
              <svg class="fa-chevron" class:rotated={!collapsed.partials} width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <polyline points="9 18 15 12 9 6"/>
              </svg>
            </button>
            {#if !collapsed.partials}
              <div class="fa-section-body">
                {#each analysis.partials as partial}
                  <div class="fa-row">
                    <label class="fa-check-row">
                      <input type="checkbox" checked={!skipped[partial.file]} onchange={() => toggleSkip(partial.file)} />
                      <span class="fa-row-name">{partial.name || partial.file}</span>
                    </label>
                    {#if partial.lines}
                      <span class="fa-row-meta">{partial.lines} lines</span>
                    {/if}
                  </div>
                {/each}
              </div>
            {/if}
          </div>
        {/if}

        <!-- Navigation -->
        {#if analysis.menus?.length}
          <div class="fa-section">
            <button class="fa-section-header" onclick={() => toggleCollapse('menus')}>
              <span class="fa-section-label">Navigation</span>
              <span class="fa-section-count">{analysis.menus.length}</span>
              <svg class="fa-chevron" class:rotated={!collapsed.menus} width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <polyline points="9 18 15 12 9 6"/>
              </svg>
            </button>
            {#if !collapsed.menus}
              <div class="fa-section-body">
                {#each analysis.menus as menu}
                  <div class="fa-row">
                    <span class="fa-row-name">{menu.name}</span>
                    {#if menu.items?.length}
                      <span class="fa-row-meta">{menu.items.length} items</span>
                    {/if}
                  </div>
                  {#if menu.items?.length}
                    <div class="fa-tree">
                      {#each menu.items as item}
                        <div class="fa-tree-item">
                          <span class="fa-tree-label">{item.label || item.url || 'Link'}</span>
                          {#if item.url}
                            <span class="fa-tree-url">{item.url}</span>
                          {/if}
                        </div>
                      {/each}
                    </div>
                  {/if}
                {/each}
              </div>
            {/if}
          </div>
        {/if}

        <!-- Pages -->
        {#if analysis.pages?.length}
          <div class="fa-section">
            <button class="fa-section-header" onclick={() => toggleCollapse('pages')}>
              <span class="fa-section-label">Pages</span>
              <span class="fa-section-count">{analysis.pages.length}</span>
              <svg class="fa-chevron" class:rotated={!collapsed.pages} width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <polyline points="9 18 15 12 9 6"/>
              </svg>
            </button>
            {#if !collapsed.pages}
              <div class="fa-section-body">
                {#each analysis.pages as page}
                  <div class="fa-row">
                    <label class="fa-check-row">
                      <input type="checkbox" checked={!skipped[page.file]} onchange={() => toggleSkip(page.file)} />
                      <span class="fa-row-name">{page.file}</span>
                    </label>
                    <span class="fa-row-meta">
                      {page.fields?.length || 0} fields
                      {#if page.sections?.length}
                        &middot; {page.sections.length} {page.sections.length === 1 ? 'section' : 'sections'}
                      {/if}
                    </span>
                  </div>
                  {#if page.sections?.length}
                    <div class="fa-page-sections">
                      {#each page.sections as section}
                        <span class="fa-section-tag">{section}</span>
                      {/each}
                    </div>
                  {/if}
                {/each}
              </div>
            {/if}
          </div>
        {/if}

        <!-- Globals -->
        {#if analysis.globals?.length}
          <div class="fa-section">
            <button class="fa-section-header" onclick={() => toggleCollapse('globals')}>
              <span class="fa-section-label">Globals</span>
              <span class="fa-section-count">{analysis.globals.length}</span>
              <svg class="fa-chevron" class:rotated={!collapsed.globals} width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <polyline points="9 18 15 12 9 6"/>
              </svg>
            </button>
            {#if !collapsed.globals}
              <div class="fa-section-body">
                {#each analysis.globals as g}
                  <div class="fa-row">
                    <span class="fa-row-name">{g.name || g.field}</span>
                    {#if g.type}
                      <span class="fa-row-meta">{g.type}</span>
                    {/if}
                  </div>
                {/each}
              </div>
            {/if}
          </div>
        {/if}

        <!-- Warnings -->
        {#if analysis.warnings?.length}
          <div class="fa-section fa-warnings-section">
            <div class="fa-section-header">
              <span class="fa-section-label">Warnings</span>
              <span class="fa-section-count">{analysis.warnings.length}</span>
            </div>
            <div class="fa-section-body">
              {#each analysis.warnings as warning}
                <div class="fa-warning">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
                    <line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
                  </svg>
                  <span>{warning}</span>
                </div>
              {/each}
            </div>
          </div>
        {/if}
      {/if}
    </div>

    <!-- Footer -->
    {#if phase === 'preview' && !error}
      <div class="fa-footer">
        <button class="btn btn-secondary" onclick={onClose}>Cancel</button>
        <button class="btn btn-primary fa-apply-btn" onclick={applyAnalysis}>
          Apply Analysis
        </button>
      </div>
    {:else if phase === 'done'}
      <div class="fa-footer">
        <button class="btn btn-primary" onclick={onClose}>Done</button>
      </div>
    {/if}
  </div>
</div>

<style>
  .fa-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.5);
    z-index: 1000;
    display: flex;
    align-items: center;
    justify-content: center;
  }

  .fa-modal {
    background: var(--bg-primary, #fff);
    border-radius: 12px;
    width: 600px;
    max-width: 92vw;
    max-height: 82vh;
    display: flex;
    flex-direction: column;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.25);
  }

  .fa-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    padding: 20px 24px 12px;
    flex-shrink: 0;
  }

  .fa-title {
    font-size: 16px;
    font-weight: 600;
    margin: 0;
    color: var(--text-primary);
  }

  .fa-subtitle {
    font-size: 13px;
    color: var(--text-tertiary);
    margin: 4px 0 0;
  }

  .fa-close {
    background: none;
    border: none;
    color: var(--text-tertiary);
    cursor: pointer;
    padding: 4px;
  }

  .fa-body {
    flex: 1;
    overflow-y: auto;
    padding: 0 24px 16px;
  }

  /* Spinner */
  .fa-spinner-wrap {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 48px 0;
    gap: 16px;
  }

  .fa-spinner {
    width: 28px;
    height: 28px;
    border: 2px solid var(--border-secondary, #e5e7eb);
    border-top-color: var(--forest, #229672);
    border-radius: 50%;
    animation: fa-spin 0.7s linear infinite;
  }

  @keyframes fa-spin {
    to { transform: rotate(360deg); }
  }

  .fa-spinner-text {
    font-size: 13px;
    color: var(--text-tertiary);
    margin: 0;
  }

  /* Error */
  .fa-error {
    padding: 20px;
    background: #fef2f2;
    border-radius: 8px;
    color: #b91c1c;
    font-size: 13px;
  }

  /* Done */
  .fa-done {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 32px 0;
    gap: 12px;
    text-align: center;
  }

  .fa-done-title {
    font-size: 15px;
    font-weight: 600;
    color: var(--text-primary);
    margin: 0;
  }

  .fa-done-stats {
    display: flex;
    flex-wrap: wrap;
    gap: 6px 16px;
    justify-content: center;
    font-size: 13px;
    color: var(--text-tertiary);
  }

  /* Sections */
  .fa-section {
    border-bottom: 1px solid var(--border-secondary, #f3f4f6);
  }

  .fa-section:last-child {
    border-bottom: none;
  }

  .fa-section-header {
    display: flex;
    align-items: center;
    gap: 8px;
    width: 100%;
    padding: 12px 0;
    background: none;
    border: none;
    cursor: pointer;
    color: var(--text-primary);
    font-family: inherit;
  }

  .fa-section-label {
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--text-tertiary);
  }

  .fa-section-count {
    font-size: 11px;
    color: var(--text-tertiary);
    margin-left: auto;
  }

  .fa-chevron {
    transition: transform 0.15s ease;
    color: var(--text-tertiary);
  }

  .fa-chevron.rotated {
    transform: rotate(90deg);
  }

  .fa-section-body {
    padding: 0 0 8px;
  }

  /* Rows */
  .fa-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 6px 0;
    min-height: 28px;
  }

  .fa-check-row {
    display: flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
  }

  .fa-check-row input[type="checkbox"] {
    width: 14px;
    height: 14px;
    accent-color: var(--forest, #229672);
  }

  .fa-row-name {
    font-size: 13px;
    color: var(--text-primary);
    font-weight: 500;
  }

  .fa-row-meta {
    font-size: 12px;
    color: var(--text-tertiary);
    flex-shrink: 0;
  }

  /* Tree items (nav) */
  .fa-tree {
    padding: 0 0 4px 16px;
  }

  .fa-tree-item {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 3px 0;
  }

  .fa-tree-label {
    font-size: 13px;
    color: var(--text-primary);
  }

  .fa-tree-url {
    font-size: 11px;
    color: var(--text-tertiary);
    font-family: var(--font-mono, monospace);
  }

  /* Page section tags */
  .fa-page-sections {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
    padding: 2px 0 6px 22px;
  }

  .fa-section-tag {
    font-size: 11px;
    color: var(--text-tertiary);
    padding: 1px 6px;
    border: 1px solid var(--border-secondary, #e5e7eb);
    border-radius: 3px;
  }

  /* Warnings */
  .fa-warnings-section .fa-section-header {
    cursor: default;
  }

  .fa-warning {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    padding: 8px 10px;
    margin-bottom: 4px;
    background: #fefce8;
    border-radius: 6px;
    font-size: 13px;
    color: #92400e;
  }

  .fa-warning svg {
    flex-shrink: 0;
    margin-top: 1px;
    color: #d97706;
  }

  /* Footer */
  .fa-footer {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    padding: 12px 24px 20px;
    border-top: 1px solid var(--border-secondary, #eee);
    flex-shrink: 0;
  }

  .fa-apply-btn {
    background: var(--forest, #229672) !important;
    border-color: var(--forest, #229672) !important;
  }

  .fa-apply-btn:hover {
    background: var(--forest-hover, #1a7a5c) !important;
    border-color: var(--forest-hover, #1a7a5c) !important;
  }
</style>
