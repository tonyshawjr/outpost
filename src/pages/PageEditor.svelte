<script>
  import { pages as pagesApi, fields as fieldsApi, ConflictError } from '$lib/api.js';
  import { currentPageId, navigate, addToast, appVersion } from '$lib/stores.js';
  import FieldRenderer from '$components/FieldRenderer.svelte';
  import SeoScore from '$components/SeoScore.svelte';
  import RevisionList from '$components/RevisionList.svelte';
  import outpostLogo from '../assets/outpost.svg';

  let pageId = $derived($currentPageId);
  let page = $state(null);
  let fields = $state([]);
  let loading = $state(true);
  let saving = $state(false);
  let dirty = $state(false);
  let changes = $state({});
  let savedAt = $state(null);
  let sidebarTab = $state('page'); // 'page' | 'seo' | 'history'
  let confirmDelete = $state(false);
  let pageVersion = $state(null);
  let conflict = $state(null);
  let revisionKey = $state(0);

  $effect(() => {
    if (pageId) loadPage(pageId);
  });

  async function loadPage(id) {
    loading = true;
    dirty = false;
    changes = {};
    savedAt = null;
    conflict = null;
    try {
      const data = await pagesApi.get(id);
      page = data.page;
      fields = data.page.fields || [];
      pageVersion = data.page.updated_at || null;
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      loading = false;
    }
  }

  function handleFieldChange(fieldId, value) {
    changes[fieldId] = value;
    dirty = true;
    fields = fields.map((f) =>
      f.id === fieldId ? { ...f, content: value } : f
    );
  }

  async function handleSave() {
    saving = true;
    try {
      const updates = Object.entries(changes)
        .filter(([id]) => id && id !== 'null')
        .map(([id, content]) => ({ id: parseInt(id), content }));

      if (updates.length > 0) {
        const pv = {};
        if (pageVersion && page?.id) pv[page.id] = pageVersion;
        const result = await fieldsApi.bulkUpdate(updates, pv);
        if (result.updated_at) pageVersion = result.updated_at;
      }

      if (page._metaChanged) {
        const result = await pagesApi.update(pageId, {
          title: page.title,
          meta_title: page.meta_title,
          meta_description: page.meta_description,
          visibility: page.visibility || 'public',
          _version: pageVersion,
        });
        if (result.updated_at) pageVersion = result.updated_at;
      }

      changes = {};
      dirty = false;
      savedAt = new Date();
      conflict = null;
      revisionKey++;
      addToast('Changes saved', 'success');
    } catch (err) {
      if (err instanceof ConflictError) {
        conflict = {
          message: err.message,
          reload: () => { conflict = null; loadPage(pageId); },
          force: () => { conflict = null; pageVersion = null; handleSave(); },
        };
      } else {
        addToast('Failed to save: ' + err.message, 'error');
      }
    } finally {
      saving = false;
    }
  }

  function handleMetaChange(key, value) {
    page = { ...page, [key]: value, _metaChanged: true };
    dirty = true;
  }

  function goBack() {
    navigate('pages');
  }

  async function handleStatusChange(newStatus) {
    try {
      await pagesApi.update(pageId, { status: newStatus });
      page = { ...page, status: newStatus };
      addToast(newStatus === 'published' ? 'Page published' : 'Page set to draft', 'success');
    } catch (err) {
      addToast(err.message, 'error');
    }
  }

  async function handleDelete() {
    try {
      await pagesApi.delete(pageId);
      addToast('Page deleted', 'success');
      navigate('pages');
    } catch (err) {
      addToast(err.message, 'error');
      confirmDelete = false;
    }
  }

  function handleGlobalKeydown(e) {
    if ((e.metaKey || e.ctrlKey) && e.key === 's') {
      e.preventDefault();
      if (dirty && !saving) handleSave();
    }
  }

  // Exclude meta_title and meta_description field types — they live in the SEO tab only
  let contentFields = $derived(fields.filter(f => f.field_type !== 'meta_title' && f.field_type !== 'meta_description'));

  let metaTitleLen = $derived((page?.meta_title || '').length);
  let metaDescLen = $derived((page?.meta_description || '').length);
</script>

<svelte:window onkeydown={handleGlobalKeydown} />

{#if loading}
  <div class="pe-loading">
    <div class="spinner"></div>
  </div>
{:else if page}
  <div class="pe">

    <!-- ── Left column ── -->
    <div class="pe-left">

      {#if conflict}
        <div class="pe-conflict-banner">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
          <span class="pe-conflict-msg">{conflict.message}</span>
          <button class="pe-conflict-btn" onclick={conflict.reload}>Reload</button>
          <button class="pe-conflict-btn pe-conflict-btn--force" onclick={conflict.force}>Save anyway</button>
        </div>
      {/if}

      <div class="pe-header">
        <button class="pe-breadcrumb" onclick={goBack}>
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
          Pages
        </button>
        <input
          class="pe-title-input"
          type="text"
          value={page.title}
          oninput={(e) => handleMetaChange('title', e.target.value)}
          placeholder="Page title"
        />
      </div>

      {#if contentFields.length > 0}
        <div class="pe-fields">
          {#each contentFields as field (field.id ?? field.field_name)}
            <FieldRenderer {field} onchange={handleFieldChange} />
          {/each}
        </div>
      {:else}
        <div class="pe-empty">
          <p class="pe-empty-title">No fields yet</p>
          <p class="pe-empty-hint">Fields are scaffolded from your theme templates. Re-activate your theme or visit this page on your site to create fields.</p>
        </div>
      {/if}

      <div class="watermark-footer">
        <div class="watermark-text">Handcrafted with 🫀 in Wilmington, NC</div>
        <div class="pe-logo-row">
          <div class="watermark-logo-wrap">
            <img src={outpostLogo} alt="" class="watermark-logo" aria-hidden="true" />
            {#if $appVersion}
              <span class="watermark-version-pill">v{$appVersion}</span>
            {/if}
          </div>
          <a href="/outpost/docs/" target="_blank" rel="noopener" class="watermark-docs-link pe-docs-inline">Developer Docs →</a>
        </div>
      </div>
    </div>

    <!-- ── Right sidebar ── -->
    <aside class="pe-sidebar">

      <!-- Tab switcher -->
      <div class="pe-tabs">
        <button class="pe-tab" class:active={sidebarTab === 'page'} onclick={() => sidebarTab = 'page'}>Page</button>
        <button class="pe-tab" class:active={sidebarTab === 'seo'} onclick={() => sidebarTab = 'seo'}>SEO</button>
        <button class="pe-tab" class:active={sidebarTab === 'history'} onclick={() => sidebarTab = 'history'}>History</button>
      </div>

      {#if sidebarTab === 'page'}
        <!-- Page tab: Slug + Status + Visibility -->
        <div class="pe-section">
          <span class="pe-section-label">Slug</span>
          <span class="pe-slug">{page.path}</span>
        </div>

        <div class="pe-section">
          <span class="pe-section-label">Status</span>
          <div class="pe-status-row">
            <select
              class="pe-status-select"
              value={page.status || 'published'}
              onchange={(e) => handleStatusChange(e.target.value)}
            >
              <option value="published">Published</option>
              <option value="draft">Draft</option>
            </select>
            <button
              class="pe-save-btn"
              onclick={handleSave}
              disabled={!dirty || saving}
            >
              {saving ? 'Saving…' : 'Save'}
            </button>
          </div>
          {#if dirty}
            <p class="pe-status-hint pe-status-hint--unsaved">Unsaved changes</p>
          {:else if savedAt}
            <p class="pe-status-hint">Saved</p>
          {/if}
        </div>

        <div class="pe-section">
          <span class="pe-section-label">Visibility</span>
          <div class="pe-vis-options">
            {#each [
              { value: 'public', label: 'Public', lock: false },
              { value: 'members', label: 'Members only', lock: true },
              { value: 'paid', label: 'Paid members', lock: true }
            ] as opt}
              <label class="pe-vis-row" class:active={(page.visibility || 'public') === opt.value}>
                <input
                  type="radio"
                  name="pe-visibility"
                  value={opt.value}
                  checked={(page.visibility || 'public') === opt.value}
                  onchange={() => handleMetaChange('visibility', opt.value)}
                />
                <span class="pe-vis-label">{opt.label}</span>
                {#if opt.lock}
                  <svg class="pe-vis-lock" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
                {/if}
              </label>
            {/each}
          </div>
        </div>

        {#if page.path !== '/'}
          <div class="pe-section pe-delete-section">
            {#if confirmDelete}
              <p class="pe-delete-confirm-text">Delete this page and its template file?</p>
              <div class="pe-delete-actions">
                <button class="btn btn-danger btn-sm" onclick={handleDelete}>Delete</button>
                <button class="btn btn-secondary btn-sm" onclick={() => confirmDelete = false}>Cancel</button>
              </div>
            {:else}
              <button class="pe-delete-btn" onclick={() => confirmDelete = true}>Delete page</button>
            {/if}
          </div>
        {/if}

      {:else if sidebarTab === 'seo'}
        <!-- SEO tab -->
        <div class="pe-section">
          <SeoScore
            title={page.title || ''}
            metaTitle={page.meta_title || ''}
            metaDescription={page.meta_description || ''}
            slug={page.path || ''}
          />
        </div>

        <div class="pe-section">
          <div class="pe-field">
            <label class="pe-section-label">Meta Title</label>
            <input
              class="pe-input"
              type="text"
              value={page.meta_title || ''}
              oninput={(e) => handleMetaChange('meta_title', e.target.value)}
              placeholder={page.title || 'Page title'}
            />
            <div class="pe-charcount" class:over={metaTitleLen > 60}>{metaTitleLen}/60</div>
          </div>
        </div>

        <div class="pe-section">
          <div class="pe-field">
            <label class="pe-section-label">Meta Description</label>
            <textarea
              class="pe-input"
              rows="4"
              value={page.meta_description || ''}
              oninput={(e) => handleMetaChange('meta_description', e.target.value)}
              placeholder="Write a concise description for search results..."
            ></textarea>
            <div class="pe-charcount" class:over={metaDescLen > 160}>{metaDescLen}/160</div>
          </div>
        </div>

        <div class="pe-section">
          <div class="sidebar-card">
            <div class="sidebar-card-title">Search Preview</div>
            <div class="pe-seo-preview">
              <div class="pe-seo-preview-title">{page.meta_title || page.title || 'Page Title'}</div>
              <div class="pe-seo-preview-url">{page.path}</div>
              <div class="pe-seo-preview-desc">{page.meta_description || 'No description set.'}</div>
            </div>
          </div>
        </div>

        <!-- Save button in SEO tab too -->
        <div class="pe-section">
          <button
            class="pe-save-btn pe-save-btn--full"
            onclick={handleSave}
            disabled={!dirty || saving}
          >
            {saving ? 'Saving…' : 'Save changes'}
          </button>
          {#if dirty}
            <p class="pe-status-hint pe-status-hint--unsaved">Unsaved changes</p>
          {:else if savedAt}
            <p class="pe-status-hint">Saved</p>
          {/if}
        </div>

      {:else if sidebarTab === 'history'}
        <!-- History tab -->
        <div class="pe-section">
          <RevisionList
            entityType="page"
            entityId={page.id}
            key={revisionKey}
            onRestore={() => loadPage(page.id)}
          />
        </div>
      {/if}

    </aside>
  </div>
{/if}

<style>
  /* ── Fills app-content; two columns each scroll independently ── */
  .pe {
    position: absolute;
    inset: 0;
    display: flex;
    overflow: hidden;
  }

  .pe-loading {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
  }

  /* ── Left column — flex column so watermark-footer can use margin-top: auto ── */
  .pe-left {
    flex: 1;
    min-width: 0;
    overflow-y: auto;
    padding: 40px 56px 0;
    background: var(--bg-primary);
    border-right: 1px solid var(--border-secondary);
    display: flex;
    flex-direction: column;
  }

  /* ── Page header: breadcrumb above title ── */
  .pe-header {
    padding-bottom: 24px;
    border-bottom: 1px solid var(--border-secondary);
    margin-bottom: 32px;
  }

  .pe-breadcrumb {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    border: none;
    background: none;
    color: var(--text-tertiary);
    font-family: var(--font-sans);
    font-size: 13px;
    cursor: pointer;
    padding: 0;
    margin-bottom: 12px;
    transition: color 0.15s;
  }

  .pe-breadcrumb:hover {
    color: var(--text-primary);
  }

  .pe-title-input {
    display: block;
    width: 100%;
    border: none;
    outline: none;
    background: none;
    font-family: var(--font-serif);
    font-size: 32px;
    font-weight: 600;
    color: var(--text-primary);
    line-height: 1.15;
    padding: 0;
    margin-bottom: 4px;
  }

  .pe-title-input::placeholder {
    color: var(--text-light);
  }

  .pe-slug {
    display: block;
    font-size: 13px;
    color: var(--text-secondary);
    font-family: var(--font-mono);
  }

  /* ── Fields ── */
  .pe-fields {
    display: flex;
    flex-direction: column;
  }

  /* ── Empty state ── */
  .pe-empty {
    padding: 48px 0;
    text-align: center;
  }

  .pe-empty-title {
    font-size: 16px;
    font-weight: 500;
    color: var(--text-secondary);
    margin-bottom: 4px;
  }

  .pe-empty-hint {
    font-size: 13px;
    color: var(--text-tertiary);
    max-width: 360px;
    margin: 0 auto;
    line-height: 1.5;
  }

  /* ── Logo row: logo centered, docs link inline on the right ── */
  .pe-logo-row {
    position: relative;
    display: flex;
    justify-content: center;
    align-items: flex-end;
    width: 100%;
  }

  .pe-docs-inline {
    position: absolute;
    right: 0;
    bottom: 10px;
  }

  /* ── Right sidebar ── */
  .pe-sidebar {
    width: 320px;
    flex-shrink: 0;
    overflow-y: auto;
    background: var(--bg-secondary);
    display: flex;
    flex-direction: column;
  }

  /* ── Tab switcher — matches RightSidebar pattern ── */
  .pe-tabs {
    display: flex;
    border-bottom: 1px solid var(--border-primary);
    flex-shrink: 0;
  }

  .pe-tab {
    flex: 1;
    background: none;
    border: none;
    border-bottom: 2px solid transparent;
    padding: 12px 0;
    font-family: var(--font-sans);
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--text-tertiary);
    cursor: pointer;
    transition: all 0.15s;
    margin-bottom: -1px;
  }

  .pe-tab:hover {
    color: var(--text-secondary);
  }

  .pe-tab.active {
    color: var(--text-primary);
    border-bottom-color: var(--text-primary);
  }

  /* ── Sidebar sections ── */
  .pe-section {
    padding: 20px 16px;
    border-bottom: 1px solid var(--border-secondary);
  }

  .pe-section:last-child {
    border-bottom: none;
  }

  .pe-section-label {
    display: block;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--text-tertiary);
    margin-bottom: 10px;
  }

  /* ── Status & Actions ── */
  .pe-status-row {
    display: flex;
    gap: 8px;
    align-items: center;
  }

  .pe-status-select {
    flex: 1;
    min-width: 0;
    padding: 7px 10px;
    border: 1px solid var(--border-primary);
    border-radius: var(--radius-sm);
    background: var(--bg-primary);
    color: var(--text-primary);
    font-family: var(--font-sans);
    font-size: 13px;
    outline: none;
    cursor: pointer;
    appearance: none;
    transition: border-color 0.15s;
  }

  .pe-status-select:focus {
    border-color: var(--accent);
  }

  .pe-save-btn {
    padding: 7px 16px;
    background: var(--accent);
    color: var(--text-inverse);
    border: none;
    border-radius: var(--radius-sm);
    font-family: var(--font-sans);
    font-size: 13px;
    font-weight: 500;
    cursor: pointer;
    white-space: nowrap;
    transition: background 0.15s;
  }

  .pe-save-btn--full {
    width: 100%;
    padding: 8px;
  }

  .pe-save-btn:hover:not(:disabled) {
    background: var(--accent-hover);
  }

  .pe-save-btn:disabled {
    opacity: 0.4;
    cursor: default;
  }

  .pe-status-hint {
    margin-top: 8px;
    font-size: 12px;
    color: var(--text-tertiary);
  }

  .pe-status-hint--unsaved {
    color: var(--warning);
  }

  /* ── SEO form fields ── */
  .pe-input {
    display: block;
    width: 100%;
    padding: 7px 10px;
    border: 1px solid var(--border-primary);
    border-radius: var(--radius-sm);
    background: var(--bg-primary);
    color: var(--text-primary);
    font-family: var(--font-sans);
    font-size: 13px;
    line-height: 1.4;
    outline: none;
    transition: border-color 0.15s;
    resize: vertical;
  }

  .pe-input:focus {
    border-color: var(--accent);
  }

  .pe-input::placeholder {
    color: var(--text-light);
  }

  .pe-charcount {
    margin-top: 4px;
    font-size: 11px;
    color: var(--text-tertiary);
    text-align: right;
    font-variant-numeric: tabular-nums;
  }

  .pe-charcount.over {
    color: var(--danger);
  }

  /* ── Search preview — matches RightSidebar rs-seo-preview style ── */
  .pe-seo-preview {
    padding-top: 4px;
  }

  .pe-seo-preview-title {
    font-size: 16px;
    font-weight: 500;
    color: #1a0dab;
    line-height: 1.3;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  :global(.dark) .pe-seo-preview-title {
    color: #8ab4f8;
  }

  .pe-seo-preview-url {
    font-size: 12px;
    color: #006621;
    margin-top: 2px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  :global(.dark) .pe-seo-preview-url {
    color: #bdc1c6;
  }

  .pe-seo-preview-desc {
    font-size: 13px;
    color: var(--text-secondary);
    line-height: 1.5;
    margin-top: 4px;
    display: -webkit-box;
    -webkit-line-clamp: 3;
    -webkit-box-orient: vertical;
    overflow: hidden;
  }

  /* ── Visibility radio group ── */
  .pe-vis-options {
    display: flex;
    flex-direction: column;
  }

  .pe-vis-row {
    display: flex;
    align-items: center;
    gap: 10px;
    height: 40px;
    padding: 0 8px;
    border-radius: var(--radius-sm);
    cursor: pointer;
    transition: background 0.1s;
  }

  .pe-vis-row:hover {
    background: var(--bg-hover);
  }

  .pe-vis-row input[type="radio"] {
    accent-color: var(--accent);
    width: 15px;
    height: 15px;
    flex-shrink: 0;
    cursor: pointer;
  }

  .pe-vis-label {
    flex: 1;
    font-size: 14px;
    color: var(--text-secondary);
    cursor: pointer;
  }

  .pe-vis-row.active .pe-vis-label {
    color: var(--accent);
    font-weight: 500;
  }

  .pe-vis-lock {
    color: var(--text-tertiary);
    flex-shrink: 0;
  }

  /* Delete page */
  .pe-delete-section {
    margin-top: auto;
    padding-top: var(--space-lg);
    border-top: 1px solid var(--border-color);
  }
  .pe-delete-btn {
    background: none;
    border: none;
    color: var(--text-tertiary);
    font-size: var(--font-size-xs);
    cursor: pointer;
    padding: 0;
  }
  .pe-delete-btn:hover {
    color: var(--color-danger, #e53e3e);
  }
  .pe-delete-confirm-text {
    font-size: var(--font-size-xs);
    color: var(--text-secondary);
    margin-bottom: var(--space-sm);
  }
  .pe-delete-actions {
    display: flex;
    gap: var(--space-xs);
  }

  /* ── Conflict banner ── */
  .pe-conflict-banner {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 16px;
    margin: 0 -56px 0;
    background: #fffbeb;
    border-bottom: 1px solid #f5e6b8;
    font-size: 13px;
    color: #92400e;
    flex-shrink: 0;
  }
  :global(.dark) .pe-conflict-banner {
    background: #332b10;
    border-color: #4a3f1a;
    color: #fbbf24;
  }
  .pe-conflict-msg { flex: 1; }
  .pe-conflict-btn {
    padding: 4px 12px;
    border-radius: var(--radius-sm);
    font-size: 12px;
    font-weight: 500;
    cursor: pointer;
    border: 1px solid #e5d5a0;
    background: #fff;
    color: #92400e;
    font-family: var(--font-sans);
    white-space: nowrap;
  }
  .pe-conflict-btn:hover { background: #fef3c7; }
  :global(.dark) .pe-conflict-btn {
    background: #4a3f1a;
    border-color: #5c4f22;
    color: #fbbf24;
  }
  :global(.dark) .pe-conflict-btn:hover { background: #5c4f22; }
  .pe-conflict-btn--force {
    background: none;
    border-color: transparent;
    color: #b45309;
    text-decoration: underline;
    text-underline-offset: 2px;
  }
  :global(.dark) .pe-conflict-btn--force {
    background: none;
    border-color: transparent;
    color: #d97706;
  }

  /* ── Mobile ── */
  @media (max-width: 768px) {
    .pe {
      flex-direction: column;
      bottom: calc(var(--mobile-nav-height) + env(safe-area-inset-bottom, 0px));
    }

    .pe-left {
      padding: 20px 16px 0;
      border-right: none;
    }

    .pe-sidebar {
      width: 100%;
      border-top: 1px solid var(--border-primary);
      max-height: 50vh;
    }

    .pe-title-input {
      font-size: 24px;
    }

    .pe-conflict-banner {
      margin-left: -16px;
      margin-right: -16px;
    }
  }
</style>
