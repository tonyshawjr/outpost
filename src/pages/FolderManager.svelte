<script>
  import { folders as foldersApi, labels as labelsApi, collections as collectionsApi } from '$lib/api.js';
  import { currentFolderCollectionId, currentFolderId, collectionsList, addToast, navigate } from '$lib/stores.js';
  import { slugify } from '$lib/utils.js';
  import EmptyState from '$components/EmptyState.svelte';

  let filterCollId = $state(null);
  let initialFolderCollId = $derived($currentFolderCollectionId);
  let initialFolderId = $derived($currentFolderId);
  let colls = $derived($collectionsList);

  let folderList = $state([]);
  let loading = $state(true);
  let expandedFolder = $state(null);
  let labelsMap = $state({});
  let labelsLoading = $state({});

  // New folder form
  let showNewFolder = $state(false);
  let newFolderName = $state('');
  let newFolderSingularName = $state('');
  let newFolderSlug = $state('');
  let newFolderType = $state('flat');
  let newFolderCollId = $state(null);
  let creatingFolder = $state(false);

  // New label form per folder
  let newLabelName = $state({});
  let creatingLabel = $state({});

  // Initialize from navigation params
  let initialized = $state(false);
  $effect(() => {
    if (!initialized) {
      if (initialFolderCollId) {
        filterCollId = initialFolderCollId;
      }
      initialized = true;
    }
  });

  // Load collections if not loaded
  $effect(() => {
    if (colls.length === 0) {
      collectionsApi.list().then(data => {
        collectionsList.set(data.collections || []);
      }).catch(() => {});
    }
  });

  // Load folders when initialized or filter changes
  $effect(() => {
    if (initialized) {
      loadFolders();
    }
  });

  // Auto-expand from nav param
  $effect(() => {
    if (initialFolderId && folderList.length > 0 && expandedFolder !== initialFolderId) {
      const folder = folderList.find(t => t.id === initialFolderId);
      if (folder) {
        toggleFolder(folder);
      }
    }
  });

  async function loadFolders() {
    loading = true;
    try {
      const data = await foldersApi.list(filterCollId || undefined);
      folderList = data.folders || [];
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      loading = false;
    }
  }

  function autoFolderSlug() {
    newFolderSlug = slugify(newFolderName);
  }

  async function toggleFolder(folder) {
    if (expandedFolder === folder.id) {
      expandedFolder = null;
      return;
    }
    expandedFolder = folder.id;
    if (!labelsMap[folder.id]) {
      await loadLabels(folder.id);
    }
  }

  async function loadLabels(folderId) {
    labelsLoading = { ...labelsLoading, [folderId]: true };
    try {
      const data = await labelsApi.list(folderId);
      labelsMap = { ...labelsMap, [folderId]: data.labels || [] };
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      labelsLoading = { ...labelsLoading, [folderId]: false };
    }
  }

  function openNewFolder() {
    newFolderName = '';
    newFolderSingularName = '';
    newFolderSlug = '';
    newFolderType = 'flat';
    newFolderCollId = filterCollId;
    showNewFolder = true;
  }

  function cancelNewFolder() {
    showNewFolder = false;
    newFolderName = '';
    newFolderSingularName = '';
    newFolderSlug = '';
    newFolderType = 'flat';
    newFolderCollId = null;
  }

  async function createFolder() {
    if (!newFolderName.trim() || !newFolderSlug.trim() || !newFolderCollId) return;
    creatingFolder = true;
    try {
      await foldersApi.create({
        collection_id: newFolderCollId,
        name: newFolderName.trim(),
        singular_name: newFolderSingularName.trim(),
        slug: newFolderSlug.trim(),
        type: newFolderType,
      });
      addToast('Folder created', 'success');
      cancelNewFolder();
      await loadFolders();
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      creatingFolder = false;
    }
  }

  async function deleteFolder(folder) {
    if (!confirm(`Delete folder "${folder.name}" and all its labels? This cannot be undone.`)) return;
    try {
      await foldersApi.delete(folder.id);
      if (expandedFolder === folder.id) expandedFolder = null;
      addToast('Folder deleted', 'success');
      await loadFolders();
    } catch (err) {
      addToast(err.message, 'error');
    }
  }

  async function createLabel(folderId) {
    const name = (newLabelName[folderId] || '').trim();
    if (!name) return;
    creatingLabel = { ...creatingLabel, [folderId]: true };
    try {
      await labelsApi.create({
        taxonomy_id: folderId,
        name: name,
        slug: slugify(name),
      });
      newLabelName = { ...newLabelName, [folderId]: '' };
      addToast('Label added', 'success');
      await loadLabels(folderId);
      await loadFolders();
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      creatingLabel = { ...creatingLabel, [folderId]: false };
    }
  }

  async function deleteLabel(labelId, folderId) {
    try {
      await labelsApi.delete(labelId);
      addToast('Label deleted', 'success');
      await loadLabels(folderId);
      await loadFolders();
    } catch (err) {
      addToast(err.message, 'error');
    }
  }

  function handleNewFolderKeydown(e) {
    if (e.key === 'Enter') {
      e.preventDefault();
      createFolder();
    }
    if (e.key === 'Escape') {
      cancelNewFolder();
    }
  }

  function handleNewLabelKeydown(e, folderId) {
    if (e.key === 'Enter') {
      e.preventDefault();
      createLabel(folderId);
    }
  }

  function getCollectionName(collId) {
    const c = colls.find(c => c.id === collId);
    return c?.name || 'Unknown';
  }

  let displayedFolders = $derived(
    filterCollId
      ? folderList.filter(t => t.collection_id === filterCollId)
      : folderList
  );
</script>

<div class="tax-page">
  <!-- Page Header -->
  <div class="page-header">
    <div class="page-header-icon sage">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M20.59 13.41l-7.17 7.17a2 2 0 01-2.83 0L2 12V2h10l8.59 8.59a2 2 0 010 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>
    </div>
    <div class="page-header-content">
      <h1 class="page-title">Folders</h1>
      <p class="page-subtitle">Manage tags, categories, and custom groupings</p>
    </div>
    <div class="page-header-actions">
      {#if !showNewFolder}
        <button class="btn btn-primary" onclick={openNewFolder}>
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          New Folder
        </button>
      {/if}
    </div>
  </div>

  <!-- Collection Filter -->
  {#if colls.length > 1}
    <div class="tax-filter">
      <button
        class="tax-filter-btn"
        class:active={filterCollId === null}
        onclick={() => { filterCollId = null; expandedFolder = null; }}
      >All</button>
      {#each colls as c}
        <button
          class="tax-filter-btn"
          class:active={filterCollId === c.id}
          onclick={() => { filterCollId = c.id; expandedFolder = null; }}
        >{c.name}</button>
      {/each}
    </div>
  {/if}

  {#if loading}
    <div class="loading-overlay"><div class="spinner"></div></div>
  {:else}
    <!-- New Folder Form — flat inline -->
    {#if showNewFolder}
      <div class="tax-create">
        <div class="tax-create-fields">
          <div class="tax-create-field tax-create-name">
            <label class="tax-create-label" for="new-tax-name">Name</label>
            <input
              id="new-tax-name"
              class="input"
              type="text"
              bind:value={newFolderName}
              oninput={autoFolderSlug}
              onkeydown={handleNewFolderKeydown}
              placeholder="e.g. Categories"
            />
          </div>
          <div class="tax-create-field tax-create-singular">
            <label class="tax-create-label" for="new-tax-singular">Singular</label>
            <input
              id="new-tax-singular"
              class="input"
              type="text"
              bind:value={newFolderSingularName}
              onkeydown={handleNewFolderKeydown}
              placeholder="e.g. Category"
            />
          </div>
          <div class="tax-create-field tax-create-slug">
            <label class="tax-create-label" for="new-tax-slug">Slug</label>
            <input
              id="new-tax-slug"
              class="input"
              type="text"
              bind:value={newFolderSlug}
              onkeydown={handleNewFolderKeydown}
              placeholder="categories"
            />
          </div>
          <div class="tax-create-field tax-create-type">
            <label class="tax-create-label">Type</label>
            <div class="tax-type-toggle">
              <button class="tax-type-btn" class:active={newFolderType === 'flat'} onclick={() => newFolderType = 'flat'}>Flat</button>
              <button class="tax-type-btn" class:active={newFolderType === 'hierarchical'} onclick={() => newFolderType = 'hierarchical'}>Hierarchical</button>
            </div>
          </div>
          <div class="tax-create-field tax-create-coll">
            <label class="tax-create-label">Collection</label>
            <div class="tax-coll-toggle">
              {#each colls as c}
                <button class="tax-coll-btn" class:active={newFolderCollId === c.id} onclick={() => newFolderCollId = c.id}>{c.name}</button>
              {/each}
            </div>
          </div>
        </div>
        <div class="tax-create-actions">
          <button class="btn btn-ghost btn-sm" onclick={cancelNewFolder}>Cancel</button>
          <button
            class="btn btn-primary btn-sm"
            onclick={createFolder}
            disabled={!newFolderName.trim() || !newFolderSlug.trim() || !newFolderCollId || creatingFolder}
          >
            {creatingFolder ? 'Creating...' : 'Create'}
          </button>
        </div>
      </div>
    {/if}

    <!-- Folder List — flat rows -->
    {#if displayedFolders.length === 0 && !showNewFolder}
      <EmptyState
        title="No folders yet"
        description="Folders let you organize collection items with categories, tags, and more."
        ctaLabel="Create Your First Folder"
        ctaAction={openNewFolder}
      />
    {:else}
      <div class="tax-list">
        <!-- Column headers -->
        <div class="tax-list-header">
          <span class="tax-col-name">Name</span>
          <span class="tax-col-collection">Collection</span>
          <span class="tax-col-type">Type</span>
          <span class="tax-col-count">Labels</span>
          <span class="tax-col-actions"></span>
        </div>

        {#each displayedFolders as folder (folder.id)}
          {@const isExpanded = expandedFolder === folder.id}
          {@const folderLabels = labelsMap[folder.id] || []}
          {@const isLabelsLoading = labelsLoading[folder.id]}

          <!-- Folder Row -->
          <div class="tax-row" class:expanded={isExpanded}>
            <button
              class="tax-row-header"
              onclick={() => toggleFolder(folder)}
            >
              <span class="tax-col-name">
                <svg class="tax-chevron" class:rotated={isExpanded} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
                <span class="tax-name">{folder.name}</span>
                <span class="tax-slug">/{folder.slug}</span>
              </span>
              <span class="tax-col-collection">
                <span class="tax-coll-label">{folder.collection_name || getCollectionName(folder.collection_id)}</span>
              </span>
              <span class="tax-col-type">{folder.type || 'flat'}</span>
              <span class="tax-col-count">{folder.label_count ?? folder.term_count ?? 0}</span>
              <span class="tax-col-actions">
                <span
                  class="tax-edit-btn"
                  role="button"
                  tabindex="0"
                  onclick={(e) => { e.stopPropagation(); navigate('folder-edit', { folderId: folder.id }); }}
                  onkeydown={(e) => { if (e.key === 'Enter') { e.stopPropagation(); navigate('folder-edit', { folderId: folder.id }); } }}
                  title="Edit folder"
                  aria-label="Edit folder"
                >
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                </span>
                <span
                  class="tax-delete-btn"
                  role="button"
                  tabindex="0"
                  onclick={(e) => { e.stopPropagation(); deleteFolder(folder); }}
                  onkeydown={(e) => { if (e.key === 'Enter') { e.stopPropagation(); deleteFolder(folder); } }}
                  title="Delete folder"
                  aria-label="Delete folder"
                >
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/></svg>
                </span>
              </span>
            </button>

            <!-- Expanded: Labels -->
            {#if isExpanded}
              <div class="tax-terms">
                {#if isLabelsLoading}
                  <div class="tax-terms-loading">
                    <div class="spinner" style="width: 18px; height: 18px;"></div>
                  </div>
                {:else}
                  <!-- Add Label -->
                  <div class="tax-term-add">
                    <input
                      class="input tax-term-input"
                      type="text"
                      placeholder="Add a label..."
                      bind:value={newLabelName[folder.id]}
                      onkeydown={(e) => handleNewLabelKeydown(e, folder.id)}
                    />
                    <button
                      class="btn btn-primary btn-sm"
                      onclick={() => createLabel(folder.id)}
                      disabled={!(newLabelName[folder.id] || '').trim() || creatingLabel[folder.id]}
                    >
                      {creatingLabel[folder.id] ? 'Adding...' : 'Add'}
                    </button>
                  </div>

                  {#if folderLabels.length === 0}
                    <div class="tax-terms-empty">No labels yet. Add one above.</div>
                  {:else}
                    {#each folderLabels as label (label.id)}
                      <div class="tax-term-row" style={folder.type === 'hierarchical' && label.parent_id ? 'padding-left: 48px;' : ''}>
                        <span class="tax-term-name">{label.name}</span>
                        <span class="tax-term-slug">/{label.slug}</span>
                        <span class="tax-term-count">{label.item_count !== undefined ? `${label.item_count} item${label.item_count !== 1 ? 's' : ''}` : ''}</span>
                        <button
                          class="tax-term-delete"
                          onclick={() => deleteLabel(label.id, folder.id)}
                          title="Delete label"
                          aria-label="Delete label"
                        >
                          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                        </button>
                      </div>
                    {/each}
                  {/if}
                {/if}
              </div>
            {/if}
          </div>
        {/each}
      </div>
    {/if}
  {/if}
</div>

<style>
  .tax-page {
    max-width: var(--content-width);
  }

  /* Filter — just text links */
  .tax-filter {
    display: flex;
    gap: var(--space-lg);
    margin-bottom: var(--space-2xl);
  }

  .tax-filter-btn {
    padding: 0;
    font-size: 14px;
    font-weight: 500;
    color: var(--text-tertiary);
    background: none;
    border: none;
    cursor: pointer;
    transition: color 0.1s;
  }

  .tax-filter-btn:hover {
    color: var(--text-primary);
  }

  .tax-filter-btn.active {
    color: var(--text-primary);
  }

  /* Create form */
  .tax-create {
    padding: 0 0 var(--space-xl);
    margin-bottom: var(--space-xl);
  }

  .tax-create-fields {
    display: flex;
    gap: var(--space-lg);
    flex-wrap: wrap;
    align-items: flex-end;
  }

  .tax-create-field {
    display: flex;
    flex-direction: column;
    gap: 6px;
  }

  .tax-create-name { flex: 1; min-width: 160px; }
  .tax-create-singular { flex: 0 0 140px; }
  .tax-create-slug { flex: 0 0 130px; }
  .tax-create-type, .tax-create-coll { flex: 0 0 auto; }

  .tax-create-label {
    font-size: 11px;
    font-weight: 500;
    letter-spacing: 0.03em;
    color: var(--text-tertiary);
  }

  .tax-type-toggle, .tax-coll-toggle {
    display: flex;
    gap: var(--space-md);
    height: 36px;
    align-items: center;
  }

  .tax-type-btn, .tax-coll-btn {
    padding: 0;
    font-size: 14px;
    font-weight: 500;
    color: var(--text-tertiary);
    background: none;
    border: none;
    cursor: pointer;
    transition: color 0.1s;
    white-space: nowrap;
  }

  .tax-type-btn:hover, .tax-coll-btn:hover { color: var(--text-primary); }
  .tax-type-btn.active, .tax-coll-btn.active { color: var(--text-primary); }

  .tax-create-actions {
    display: flex;
    gap: var(--space-sm);
    justify-content: flex-end;
    margin-top: var(--space-lg);
  }

  /* List */
  .tax-list {
    display: flex;
    flex-direction: column;
  }

  .tax-list-header {
    display: grid;
    grid-template-columns: 1fr 140px 100px 60px 64px;
    gap: var(--space-md);
    align-items: center;
    padding: 0 var(--space-sm) var(--space-sm);
    font-size: 11px;
    font-weight: 500;
    color: var(--text-tertiary);
    border-bottom: 1px solid var(--border-secondary);
  }

  .tax-row {}

  .tax-row-header {
    display: grid;
    grid-template-columns: 1fr 140px 100px 60px 64px;
    gap: var(--space-md);
    align-items: center;
    width: 100%;
    padding: 10px var(--space-sm);
    background: none;
    border: none;
    border-bottom: 1px solid var(--border-secondary);
    cursor: pointer;
    transition: background 0.1s;
    text-align: left;
    font-family: inherit;
    color: inherit;
  }

  .tax-row-header:hover {
    background: var(--bg-hover);
  }

  .tax-col-name {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
    min-width: 0;
  }

  .tax-chevron {
    width: 14px;
    height: 14px;
    flex-shrink: 0;
    color: var(--text-tertiary);
    transition: transform 0.15s;
  }

  .tax-chevron.rotated { transform: rotate(90deg); }

  .tax-name {
    font-size: 14px;
    font-weight: 500;
    color: var(--text-primary);
  }

  .tax-slug {
    font-size: 12px;
    color: var(--text-tertiary);
    font-family: var(--font-mono);
  }

  .tax-col-collection { font-size: 13px; }
  .tax-coll-label { color: var(--text-secondary); }
  .tax-col-type { font-size: 13px; color: var(--text-tertiary); }

  .tax-col-count {
    font-size: 13px;
    color: var(--text-secondary);
    font-variant-numeric: tabular-nums;
    text-align: right;
  }

  .tax-col-actions { display: flex; justify-content: flex-end; }

  .tax-edit-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    color: var(--text-tertiary);
    opacity: 0;
    cursor: pointer;
    transition: opacity 0.1s;
  }

  .tax-row-header:hover .tax-edit-btn { opacity: 1; }
  .tax-edit-btn:hover { color: var(--accent); }

  .tax-delete-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    color: var(--text-tertiary);
    opacity: 0;
    cursor: pointer;
    transition: opacity 0.1s;
  }

  .tax-row-header:hover .tax-delete-btn { opacity: 1; }
  .tax-delete-btn:hover { color: var(--danger); }

  /* Labels */
  .tax-terms {
    padding: var(--space-sm) 0 var(--space-md) 38px;
  }

  .tax-terms-loading {
    display: flex;
    align-items: center;
    justify-content: center;
    padding: var(--space-lg);
  }

  .tax-term-add {
    display: flex;
    gap: var(--space-sm);
    margin-bottom: var(--space-sm);
    max-width: 360px;
  }

  .tax-term-input {
    flex: 1;
    height: 30px;
    font-size: 13px;
    border-color: var(--border-secondary);
  }

  .tax-terms-empty {
    font-size: 13px;
    color: var(--text-tertiary);
    padding: var(--space-xs) 0;
  }

  .tax-term-row {
    display: flex;
    align-items: center;
    gap: var(--space-md);
    padding: 4px 0;
  }

  .tax-term-row:hover .tax-term-delete { opacity: 1; }

  .tax-term-name {
    font-size: 14px;
    color: var(--text-primary);
  }

  .tax-term-slug {
    font-size: 11px;
    color: var(--text-tertiary);
    font-family: var(--font-mono);
  }

  .tax-term-count {
    margin-left: auto;
    font-size: 11px;
    color: var(--text-tertiary);
  }

  .tax-term-delete {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 20px;
    height: 20px;
    background: none;
    border: none;
    color: var(--text-tertiary);
    opacity: 0;
    cursor: pointer;
    transition: opacity 0.1s;
  }

  .tax-term-delete:hover { color: var(--danger); }

  @media (max-width: 768px) {
    .tax-list-header { display: none; }
    .tax-row-header { display: flex; flex-wrap: wrap; gap: var(--space-sm); }
    .tax-col-collection, .tax-col-type { font-size: 12px; }
    .tax-create-fields { flex-direction: column; }
    .tax-create-name { min-width: 0; }
    .tax-create-singular { flex: 1 1 100%; }
    .tax-create-slug { flex: 1 1 100%; }
    .tax-create-type, .tax-create-coll { flex: 1; }
    .tax-filter { overflow-x: auto; flex-wrap: wrap; }
    .tax-create-modal { max-width: calc(100vw - 2rem); }
  }
</style>
