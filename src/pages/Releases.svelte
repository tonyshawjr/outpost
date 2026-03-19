<script>
  import { onMount } from 'svelte';
  import { releases as releasesApi } from '$lib/api.js';
  import { addToast, navigate } from '$lib/stores.js';

  let releasesList = $state([]);
  let loading = $state(true);
  let showCreateModal = $state(false);
  let editingRelease = $state(null);
  let selectedRelease = $state(null);
  let selectedReleaseData = $state(null);
  let loadingDetail = $state(false);

  // Form state
  let formName = $state('');
  let formDescription = $state('');
  let saving = $state(false);

  // Action states
  let publishing = $state(false);
  let rollingBack = $state(false);
  let deleting = $state(null);
  let publishConfirm = $state(null);
  let rollbackConfirm = $state(null);
  let deleteConfirm = $state(null);

  // Add change form
  let showAddChange = $state(false);
  let changeEntityType = $state('item');
  let changeEntityId = $state('');
  let changeEntityName = $state('');
  let changeAction = $state('update');
  let addingChange = $state(false);

  onMount(() => {
    loadReleases();
  });

  async function loadReleases() {
    loading = true;
    try {
      const data = await releasesApi.list();
      releasesList = data.releases || [];
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      loading = false;
    }
  }

  async function loadRelease(id) {
    loadingDetail = true;
    selectedRelease = id;
    try {
      const data = await releasesApi.get(id);
      selectedReleaseData = data;
    } catch (err) {
      addToast(err.message, 'error');
      selectedRelease = null;
    } finally {
      loadingDetail = false;
    }
  }

  function openCreate() {
    formName = '';
    formDescription = '';
    editingRelease = null;
    showCreateModal = true;
  }

  function openEdit(release) {
    formName = release.name;
    formDescription = release.description || '';
    editingRelease = release;
    showCreateModal = true;
  }

  function closeModal() {
    showCreateModal = false;
    editingRelease = null;
  }

  async function handleSave() {
    if (!formName.trim()) {
      addToast('Name is required', 'error');
      return;
    }
    saving = true;
    try {
      if (editingRelease) {
        await releasesApi.update(editingRelease.id, { name: formName, description: formDescription });
        addToast('Release updated', 'success');
      } else {
        await releasesApi.create({ name: formName, description: formDescription });
        addToast('Release created', 'success');
      }
      closeModal();
      await loadReleases();
      if (selectedRelease) await loadRelease(selectedRelease);
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      saving = false;
    }
  }

  async function handlePublish(id) {
    if (publishConfirm !== id) {
      publishConfirm = id;
      return;
    }
    publishing = true;
    try {
      await releasesApi.publish(id);
      addToast('Release published successfully', 'success');
      publishConfirm = null;
      await loadReleases();
      if (selectedRelease === id) await loadRelease(id);
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      publishing = false;
    }
  }

  async function handleRollback(id) {
    if (rollbackConfirm !== id) {
      rollbackConfirm = id;
      return;
    }
    rollingBack = true;
    try {
      await releasesApi.rollback(id);
      addToast('Release rolled back successfully', 'success');
      rollbackConfirm = null;
      await loadReleases();
      if (selectedRelease === id) await loadRelease(id);
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      rollingBack = false;
    }
  }

  async function handleDelete(id) {
    if (deleteConfirm !== id) {
      deleteConfirm = id;
      return;
    }
    try {
      await releasesApi.delete(id);
      addToast('Release deleted', 'success');
      deleteConfirm = null;
      if (selectedRelease === id) {
        selectedRelease = null;
        selectedReleaseData = null;
      }
      await loadReleases();
    } catch (err) {
      addToast(err.message, 'error');
    }
  }

  async function handleAddChange() {
    if (!changeEntityId || !changeEntityType || !changeAction) {
      addToast('All fields are required', 'error');
      return;
    }
    addingChange = true;
    try {
      await releasesApi.addChange({
        release_id: selectedRelease,
        entity_type: changeEntityType,
        entity_id: parseInt(changeEntityId),
        entity_name: changeEntityName,
        action: changeAction,
        snapshot_before: null,
        snapshot_after: null,
      });
      addToast('Change added', 'success');
      showAddChange = false;
      changeEntityId = '';
      changeEntityName = '';
      await loadRelease(selectedRelease);
      await loadReleases();
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      addingChange = false;
    }
  }

  async function handleRemoveChange(changeId) {
    try {
      await releasesApi.removeChange(changeId);
      addToast('Change removed', 'success');
      await loadRelease(selectedRelease);
      await loadReleases();
    } catch (err) {
      addToast(err.message, 'error');
    }
  }

  function backToList() {
    selectedRelease = null;
    selectedReleaseData = null;
  }

  function formatDate(dateStr) {
    if (!dateStr) return '';
    const d = new Date(dateStr + 'Z');
    return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
      + ' ' + d.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
  }

  function entityTypeLabel(type) {
    const labels = { field: 'Field', item: 'Item', menu: 'Menu', page: 'Page', collection: 'Collection', setting: 'Setting' };
    return labels[type] || type;
  }

  function actionLabel(action) {
    const labels = { create: 'Created', update: 'Updated', delete: 'Deleted' };
    return labels[action] || action;
  }

  function statusColor(status) {
    switch (status) {
      case 'draft': return 'var(--text-muted)';
      case 'published': return 'var(--color-success, #22c55e)';
      case 'rolled_back': return 'var(--color-warning, #f59e0b)';
      default: return 'var(--text-muted)';
    }
  }
</script>

<div class="rl">
  {#if selectedRelease && selectedReleaseData}
    <!-- Detail View -->
    <div class="page-header">
      <div class="page-header-icon indigo">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
      </div>
      <div class="page-header-content">
        <button class="rl-back" onclick={backToList}>
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
          Back to Releases
        </button>
        <h1 class="page-title">{selectedReleaseData.name}</h1>
        {#if selectedReleaseData.description}
          <p class="page-subtitle">{selectedReleaseData.description}</p>
        {/if}
      </div>
      <div class="page-header-actions">
        {#if selectedReleaseData.status === 'draft'}
          <button class="btn btn-secondary" onclick={() => openEdit(selectedReleaseData)}>Edit</button>
          {#if selectedReleaseData.changes?.length > 0}
            {#if publishConfirm === selectedReleaseData.id}
              <button class="btn btn-primary" onclick={() => handlePublish(selectedReleaseData.id)} disabled={publishing}>
                {publishing ? 'Publishing...' : 'Confirm Publish'}
              </button>
              <button class="btn btn-secondary" onclick={() => { publishConfirm = null; }}>Cancel</button>
            {:else}
              <button class="btn btn-primary" onclick={() => handlePublish(selectedReleaseData.id)}>Publish Release</button>
            {/if}
          {/if}
        {:else if selectedReleaseData.status === 'published'}
          {#if rollbackConfirm === selectedReleaseData.id}
            <button class="btn btn-primary rl-btn-warning" onclick={() => handleRollback(selectedReleaseData.id)} disabled={rollingBack}>
              {rollingBack ? 'Rolling back...' : 'Confirm Rollback'}
            </button>
            <button class="btn btn-secondary" onclick={() => { rollbackConfirm = null; }}>Cancel</button>
          {:else}
            <button class="btn btn-secondary" onclick={() => handleRollback(selectedReleaseData.id)}>Roll Back</button>
          {/if}
        {/if}
      </div>
    </div>

    <!-- Status Bar -->
    <div class="rl-status-bar">
      <span class="rl-status-badge" style:color={statusColor(selectedReleaseData.status)}>
        {selectedReleaseData.status === 'rolled_back' ? 'Rolled Back' : selectedReleaseData.status.charAt(0).toUpperCase() + selectedReleaseData.status.slice(1)}
      </span>
      <span class="rl-meta-sep"></span>
      <span class="rl-meta-text">{selectedReleaseData.change_count || 0} change{selectedReleaseData.change_count !== 1 ? 's' : ''}</span>
      <span class="rl-meta-sep"></span>
      <span class="rl-meta-text">Created by {selectedReleaseData.created_by_name || 'Unknown'}</span>
      {#if selectedReleaseData.published_at}
        <span class="rl-meta-sep"></span>
        <span class="rl-meta-text">Published {formatDate(selectedReleaseData.published_at)}</span>
      {/if}
    </div>

    <!-- Changes List -->
    <div class="rl-section">
      <div class="rl-section-header">
        <div class="rl-label">Changes</div>
        {#if selectedReleaseData.status === 'draft'}
          <button class="rl-add-btn" onclick={() => { showAddChange = !showAddChange; }}>
            {showAddChange ? 'Cancel' : '+ Add Change'}
          </button>
        {/if}
      </div>

      {#if showAddChange}
        <div class="rl-add-change-form">
          <div class="rl-form-row">
            <div class="rl-form-field">
              <label class="rl-field-label">Type</label>
              <select class="rl-select" bind:value={changeEntityType}>
                <option value="item">Item</option>
                <option value="field">Field</option>
                <option value="page">Page</option>
                <option value="menu">Menu</option>
                <option value="collection">Collection</option>
              </select>
            </div>
            <div class="rl-form-field">
              <label class="rl-field-label">Action</label>
              <select class="rl-select" bind:value={changeAction}>
                <option value="update">Update</option>
                <option value="create">Create</option>
                <option value="delete">Delete</option>
              </select>
            </div>
            <div class="rl-form-field">
              <label class="rl-field-label">Entity ID</label>
              <input type="number" class="rl-input" bind:value={changeEntityId} placeholder="e.g. 42" />
            </div>
            <div class="rl-form-field rl-form-field-grow">
              <label class="rl-field-label">Label (optional)</label>
              <input type="text" class="rl-input" bind:value={changeEntityName} placeholder="e.g. Homepage Hero" />
            </div>
          </div>
          <button class="btn btn-primary btn-sm" onclick={handleAddChange} disabled={addingChange}>
            {addingChange ? 'Adding...' : 'Add Change'}
          </button>
        </div>
      {/if}

      {#if loadingDetail}
        <div class="loading-overlay"><div class="spinner"></div></div>
      {:else if !selectedReleaseData.changes?.length}
        <div class="rl-empty">
          <p class="rl-empty-text">No changes in this release yet.</p>
        </div>
      {:else}
        <div class="rl-changes-list">
          {#each selectedReleaseData.changes as change (change.id)}
            <div class="rl-change-row">
              <div class="rl-change-type">
                <span class="rl-change-type-badge">{entityTypeLabel(change.entity_type)}</span>
              </div>
              <div class="rl-change-info">
                <span class="rl-change-name">{change.entity_name || `#${change.entity_id}`}</span>
                <span class="rl-change-action" class:rl-action-create={change.action === 'create'} class:rl-action-update={change.action === 'update'} class:rl-action-delete={change.action === 'delete'}>
                  {actionLabel(change.action)}
                </span>
              </div>
              <div class="rl-change-date">{formatDate(change.created_at)}</div>
              {#if selectedReleaseData.status === 'draft'}
                <button class="rl-change-remove" onclick={() => handleRemoveChange(change.id)} title="Remove change">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
              {/if}
            </div>
          {/each}
        </div>
      {/if}
    </div>

  {:else}
    <!-- List View -->
    <div class="page-header">
      <div class="page-header-icon indigo">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
      </div>
      <div class="page-header-content">
        <h1 class="page-title">Releases</h1>
        <p class="page-subtitle">Bundle content changes and publish them together.</p>
      </div>
      <div class="page-header-actions">
        <button class="btn btn-primary" onclick={openCreate}>New Release</button>
      </div>
    </div>

    {#if loading}
      <div class="loading-overlay"><div class="spinner"></div></div>
    {:else if releasesList.length === 0}
      <div class="rl-empty-state">
        <div class="rl-empty-icon">
          <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1"><path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
        </div>
        <h3 class="rl-empty-title">No releases yet</h3>
        <p class="rl-empty-desc">Create a release to bundle multiple content changes and publish them all at once.</p>
        <button class="btn btn-primary" onclick={openCreate}>Create Your First Release</button>
      </div>
    {:else}
      <div class="rl-list">
        {#each releasesList as release (release.id)}
          <div class="rl-card" onclick={() => loadRelease(release.id)} role="button" tabindex="0" onkeydown={(e) => { if (e.key === 'Enter') loadRelease(release.id); }}>
            <div class="rl-card-main">
              <div class="rl-card-title">{release.name}</div>
              {#if release.description}
                <div class="rl-card-desc">{release.description}</div>
              {/if}
              <div class="rl-card-meta">
                <span class="rl-card-status" style:color={statusColor(release.status)}>
                  {release.status === 'rolled_back' ? 'Rolled Back' : release.status.charAt(0).toUpperCase() + release.status.slice(1)}
                </span>
                <span class="rl-meta-sep"></span>
                <span>{release.change_count || 0} change{release.change_count != 1 ? 's' : ''}</span>
                <span class="rl-meta-sep"></span>
                <span>{release.created_by_name || 'Unknown'}</span>
                <span class="rl-meta-sep"></span>
                <span>{formatDate(release.created_at)}</span>
              </div>
            </div>
            <div class="rl-card-actions" onclick={(e) => e.stopPropagation()}>
              {#if release.status === 'draft'}
                {#if deleteConfirm === release.id}
                  <button class="rl-action rl-action-danger" onclick={() => handleDelete(release.id)}>Confirm</button>
                  <button class="rl-action" onclick={() => { deleteConfirm = null; }}>Cancel</button>
                {:else}
                  <button class="rl-action rl-action-danger" onclick={() => handleDelete(release.id)}>Delete</button>
                {/if}
              {/if}
            </div>
          </div>
        {/each}
      </div>
    {/if}
  {/if}
</div>

<!-- Create/Edit Modal -->
{#if showCreateModal}
  <div class="rl-overlay" onclick={closeModal}>
    <div class="rl-modal" onclick={(e) => e.stopPropagation()}>
      <h2 class="rl-modal-title">{editingRelease ? 'Edit Release' : 'New Release'}</h2>

      <div class="rl-modal-field">
        <label class="rl-field-label" for="rl-name">Name</label>
        <input
          id="rl-name"
          type="text"
          class="rl-input"
          bind:value={formName}
          placeholder="e.g. Spring Campaign Launch"
          autofocus
        />
      </div>

      <div class="rl-modal-field">
        <label class="rl-field-label" for="rl-desc">Description</label>
        <textarea
          id="rl-desc"
          class="rl-textarea"
          bind:value={formDescription}
          placeholder="Optional — describe what this release includes"
          rows="3"
        ></textarea>
      </div>

      <div class="rl-modal-actions">
        <button class="btn btn-secondary" onclick={closeModal}>Cancel</button>
        <button class="btn btn-primary" onclick={handleSave} disabled={saving}>
          {saving ? 'Saving...' : (editingRelease ? 'Save Changes' : 'Create Release')}
        </button>
      </div>
    </div>
  </div>
{/if}

<style>
  .rl {
    max-width: var(--content-width);
    margin: 0 auto;
    padding: 0 24px 80px;
  }

  /* Back link */
  .rl-back {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    background: none;
    border: none;
    color: var(--text-muted);
    font-size: 13px;
    cursor: pointer;
    padding: 0;
    margin-bottom: 4px;
    transition: color 0.15s;
  }
  .rl-back:hover { color: var(--text-primary); }

  /* Status bar */
  .rl-status-bar {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 0;
    margin-bottom: 8px;
    font-size: 13px;
    color: var(--text-muted);
  }
  .rl-status-badge {
    font-weight: 600;
    text-transform: uppercase;
    font-size: 11px;
    letter-spacing: 0.05em;
  }
  .rl-meta-sep {
    width: 3px;
    height: 3px;
    border-radius: 50%;
    background: var(--border-color);
    flex-shrink: 0;
  }
  .rl-meta-text {
    font-size: 13px;
  }

  /* Sections */
  .rl-section { margin-top: 16px; }
  .rl-section-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 12px;
  }
  .rl-label {
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--text-muted);
  }
  .rl-add-btn {
    background: none;
    border: none;
    color: var(--text-accent, var(--color-primary, #6366f1));
    font-size: 13px;
    font-weight: 500;
    cursor: pointer;
    padding: 0;
  }
  .rl-add-btn:hover { text-decoration: underline; }

  /* Add change form */
  .rl-add-change-form {
    padding: 16px;
    border: 1px solid var(--border-color);
    border-radius: var(--radius-lg, 8px);
    margin-bottom: 16px;
    background: var(--bg-surface, var(--bg-secondary));
  }
  .rl-form-row {
    display: flex;
    gap: 12px;
    margin-bottom: 12px;
    flex-wrap: wrap;
  }
  .rl-form-field {
    display: flex;
    flex-direction: column;
    gap: 4px;
    min-width: 100px;
  }
  .rl-form-field-grow { flex: 1; min-width: 150px; }
  .rl-field-label {
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--text-muted);
  }

  /* Empty states */
  .rl-empty {
    padding: 32px;
    text-align: center;
    color: var(--text-muted);
    border: 1px dashed var(--border-color);
    border-radius: var(--radius-lg, 8px);
  }
  .rl-empty-text { font-size: 14px; margin: 0; }

  .rl-empty-state {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 80px 24px;
    text-align: center;
  }
  .rl-empty-icon {
    color: var(--text-muted);
    opacity: 0.3;
    margin-bottom: 16px;
  }
  .rl-empty-title {
    font-size: 18px;
    font-weight: 600;
    color: var(--text-primary);
    margin: 0 0 8px;
  }
  .rl-empty-desc {
    font-size: 14px;
    color: var(--text-muted);
    margin: 0 0 24px;
    max-width: 400px;
  }

  /* Release list cards */
  .rl-list {
    display: flex;
    flex-direction: column;
    gap: 1px;
  }
  .rl-card {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 16px 0;
    border: none;
    background: none;
    text-align: left;
    cursor: pointer;
    width: 100%;
    border-bottom: 1px solid var(--border-color);
    transition: background 0.1s;
  }
  .rl-card:first-child { border-top: 1px solid var(--border-color); }
  .rl-card:hover { background: var(--bg-hover, rgba(0,0,0,0.02)); }
  :global(.dark) .rl-card:hover { background: rgba(255,255,255,0.03); }

  .rl-card-main { flex: 1; min-width: 0; }
  .rl-card-title {
    font-size: 15px;
    font-weight: 600;
    color: var(--text-primary);
    margin-bottom: 2px;
  }
  .rl-card-desc {
    font-size: 13px;
    color: var(--text-muted);
    margin-bottom: 6px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
  .rl-card-meta {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 12px;
    color: var(--text-muted);
  }
  .rl-card-status {
    font-weight: 600;
    text-transform: uppercase;
    font-size: 11px;
    letter-spacing: 0.05em;
  }
  .rl-card-actions {
    display: flex;
    gap: 8px;
    flex-shrink: 0;
    margin-left: 16px;
  }

  /* Actions */
  .rl-action {
    background: none;
    border: none;
    color: var(--text-muted);
    font-size: 13px;
    cursor: pointer;
    padding: 4px 8px;
    border-radius: var(--radius-sm, 4px);
    transition: color 0.1s, background 0.1s;
  }
  .rl-action:hover { color: var(--text-primary); background: var(--bg-hover, rgba(0,0,0,0.05)); }
  .rl-action-danger { color: var(--color-danger, #ef4444); }
  .rl-action-danger:hover { color: var(--color-danger, #ef4444); background: rgba(239, 68, 68, 0.08); }

  /* Changes list */
  .rl-changes-list {
    border: 1px solid var(--border-color);
    border-radius: var(--radius-lg, 8px);
    overflow: hidden;
  }
  .rl-change-row {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 16px;
    border-bottom: 1px solid var(--border-color);
  }
  .rl-change-row:last-child { border-bottom: none; }

  .rl-change-type { flex-shrink: 0; }
  .rl-change-type-badge {
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    color: var(--text-muted);
    background: var(--bg-surface, var(--bg-secondary));
    padding: 2px 8px;
    border-radius: var(--radius-sm, 4px);
  }

  .rl-change-info {
    flex: 1;
    min-width: 0;
    display: flex;
    align-items: center;
    gap: 8px;
  }
  .rl-change-name {
    font-size: 14px;
    font-weight: 500;
    color: var(--text-primary);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
  .rl-change-action {
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.03em;
  }
  .rl-action-create { color: var(--color-success, #22c55e); }
  .rl-action-update { color: var(--color-info, #3b82f6); }
  .rl-action-delete { color: var(--color-danger, #ef4444); }

  .rl-change-date {
    flex-shrink: 0;
    font-size: 12px;
    color: var(--text-muted);
  }
  .rl-change-remove {
    flex-shrink: 0;
    background: none;
    border: none;
    color: var(--text-muted);
    cursor: pointer;
    padding: 4px;
    border-radius: var(--radius-sm, 4px);
    display: flex;
    align-items: center;
    transition: color 0.1s;
  }
  .rl-change-remove:hover { color: var(--color-danger, #ef4444); }

  .rl-btn-warning {
    background: var(--color-warning, #f59e0b) !important;
    border-color: var(--color-warning, #f59e0b) !important;
  }

  /* Modal */
  .rl-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.5);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 1000;
    padding: 24px;
  }
  .rl-modal {
    background: var(--bg-primary, #fff);
    border-radius: var(--radius-lg, 8px);
    padding: 32px;
    width: 100%;
    max-width: 480px;
    box-shadow: 0 20px 60px rgba(0,0,0,0.3);
  }
  :global(.dark) .rl-modal { background: var(--bg-primary, #1a1a2e); }

  .rl-modal-title {
    font-size: 18px;
    font-weight: 600;
    color: var(--text-primary);
    margin: 0 0 24px;
  }
  .rl-modal-field { margin-bottom: 16px; }
  .rl-modal-actions {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    margin-top: 24px;
  }

  /* Inputs */
  .rl-input, .rl-textarea, .rl-select {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid transparent;
    border-radius: var(--radius-md, 6px);
    background: var(--bg-secondary, #f5f5f5);
    color: var(--text-primary);
    font-size: 14px;
    font-family: inherit;
    transition: border-color 0.15s;
    box-sizing: border-box;
  }
  .rl-input:hover, .rl-textarea:hover, .rl-select:hover { border-color: var(--border-color); }
  .rl-input:focus, .rl-textarea:focus, .rl-select:focus {
    outline: none;
    border-color: var(--color-primary, #6366f1);
  }
  .rl-textarea { resize: vertical; min-height: 60px; }
  .rl-select { cursor: pointer; }

  /* Responsive */
  @media (max-width: 640px) {
    .rl { padding: 0 16px 60px; }
    .rl-form-row { flex-direction: column; }
    .rl-card { flex-direction: column; align-items: flex-start; gap: 8px; }
    .rl-card-actions { margin-left: 0; }
    .rl-change-row { flex-wrap: wrap; }
    .rl-change-date { width: 100%; margin-top: 4px; }
  }
</style>
