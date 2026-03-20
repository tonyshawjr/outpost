<script>
  import { currentRoute, currentCollectionSlug, editorItem, editorCollection, editorReloadSignal, revisionReloadSignal, user } from '$lib/stores.js';
  import { items as itemsApi, media as mediaApi, folders as foldersApi, labels as labelsApi, itemLabels as itemLabelsApi, workflows as workflowsApi } from '$lib/api.js';
  import { addToast, navigate } from '$lib/stores.js';
  import SeoScore from '$components/SeoScore.svelte';
  import RevisionList from '$components/RevisionList.svelte';
  import Comments from '$components/Comments.svelte';

  let route = $derived($currentRoute);
  let collSlug = $derived($currentCollectionSlug);

  // Editor state from shared stores
  let editItem = $derived($editorItem);
  let editColl = $derived($editorCollection);

  // Workflow state
  let workflow = $state(null);
  let workflowStages = $derived(workflow?.stages || []);
  let transitionHistory = $state([]);

  function getStageBySlug(slug) {
    return workflowStages.find(s => s.slug === slug) || null;
  }

  function getAvailableTransitions() {
    if (!editItem || !workflowStages.length) return [];
    const stage = getStageBySlug(editItem.status);
    if (!stage) return [];
    const role = $user?.role || '';
    return (stage.can_move_to || [])
      .map(slug => getStageBySlug(slug))
      .filter(s => s && (s.roles || []).includes(role));
  }

  async function transitionTo(toStage) {
    if (!editItem) return;
    try {
      await workflowsApi.transition(editItem.id, toStage);
      const update = { status: toStage };
      if (toStage === 'published' && !editItem.published_at) {
        update.published_at = new Date().toISOString().replace('T', ' ').slice(0, 19);
      }
      editorItem.set({ ...editItem, ...update });
      const stageDef = getStageBySlug(toStage);
      addToast(`Moved to ${stageDef?.name || toStage}`, 'success');
      loadTransitionHistory();
    } catch (err) {
      addToast(err.message, 'error');
    }
  }

  async function loadTransitionHistory() {
    if (!editItem) return;
    try {
      const data = await workflowsApi.history(editItem.id);
      transitionHistory = data.transitions || [];
    } catch (e) {
      transitionHistory = [];
    }
  }

  // Local slug editing
  let slugValue = $state('');
  let slugEditing = $state(false);
  let featuredImage = $state('');
  let excerpt = $state('');
  let publishedAt = $state('');
  let confirmDelete = $state(false);

  // Sidebar tab: 'post' or 'seo'
  let sidebarTab = $state('post');

  // SEO fields
  let metaTitle = $state('');
  let metaDescription = $state('');

  function toDatetimeLocal(dateStr) {
    if (!dateStr) return '';
    const d = new Date(dateStr.replace(' ', 'T'));
    if (isNaN(d)) return '';
    const pad = n => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
  }

  // Sync local state when editItem changes
  $effect(() => {
    if (editItem) {
      slugValue = editItem.slug || '';
      featuredImage = editItem.data?.featured_image || '';
      excerpt = editItem.data?.excerpt || '';
      publishedAt = toDatetimeLocal(editItem.published_at);
      metaTitle = editItem.data?.meta_title || '';
      metaDescription = editItem.data?.meta_description || '';
    }
  });

  function slugify(text) {
    return text.toLowerCase().replace(/[^a-z0-9-]/g, '-').replace(/-+/g, '-').replace(/^-|-$/g, '');
  }

  function commitSlug() {
    const cleaned = slugify(slugValue);
    if (cleaned && cleaned !== editItem.slug) {
      slugValue = cleaned;
      editorItem.set({ ...editItem, slug: cleaned });
    } else {
      slugValue = editItem.slug;
    }
    slugEditing = false;
  }

  function handleSlugKeydown(e) {
    if (e.key === 'Enter') {
      e.target.blur();
    } else if (e.key === 'Escape') {
      slugValue = editItem.slug;
      slugEditing = false;
      e.target.blur();
    }
  }

  function getItemUrl() {
    if (!editItem || !editColl) return '';
    const pattern = editColl.url_pattern || `/${editColl.slug}/{slug}`;
    return pattern.replace('{slug}', editItem.slug);
  }

  async function handleFeaturedUpload(e) {
    const file = e.target.files?.[0];
    if (!file) return;
    try {
      const result = await mediaApi.upload(file);
      if (result.media?.path) {
        const p = result.media.path;
        featuredImage = p.startsWith('/') ? p : '/' + p;
        // Update the editor item's data with featured_image
        const updatedData = { ...(editItem.data || {}), featured_image: featuredImage };
        editorItem.set({ ...editItem, data: updatedData });
        addToast('Featured image set', 'success');
      }
    } catch (err) {
      addToast(err.message, 'error');
    }
  }

  function removeFeaturedImage() {
    featuredImage = '';
    const updatedData = { ...(editItem.data || {}) };
    delete updatedData.featured_image;
    editorItem.set({ ...editItem, data: updatedData });
  }

  function updateExcerpt() {
    if (!editItem) return;
    const updatedData = { ...(editItem.data || {}), excerpt };
    editorItem.set({ ...editItem, data: updatedData });
  }

  function updateMetaTitle() {
    if (!editItem) return;
    const updatedData = { ...(editItem.data || {}), meta_title: metaTitle };
    editorItem.set({ ...editItem, data: updatedData });
  }

  function updateMetaDescription() {
    if (!editItem) return;
    const updatedData = { ...(editItem.data || {}), meta_description: metaDescription };
    editorItem.set({ ...editItem, data: updatedData });
  }

  async function deleteItem() {
    if (!editItem) return;
    try {
      await itemsApi.delete(editItem.id);
      addToast('Item deleted', 'success');
      navigate('collection-items', { collectionSlug: collSlug });
    } catch (err) {
      addToast(err.message, 'error');
    }
    confirmDelete = false;
  }

  function formatDate(dateStr) {
    if (!dateStr) return '—';
    return new Date(dateStr).toLocaleDateString('en-US', {
      month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit'
    });
  }

  // ─── Scheduling state ───
  let scheduledAt = $state('');

  // Sync scheduledAt from editItem
  $effect(() => {
    if (editItem) {
      scheduledAt = editItem.scheduled_at || '';
    }
  });

  function handleScheduleChange() {
    if (!editItem) return;
    const newStatus = scheduledAt ? 'scheduled' : 'draft';
    editorItem.set({ ...editItem, scheduled_at: scheduledAt, status: newStatus });
  }

  function clearSchedule() {
    scheduledAt = '';
    if (editItem) {
      editorItem.set({ ...editItem, scheduled_at: '', status: 'draft' });
    }
  }

  function handlePublishedAtChange() {
    if (!editItem) return;
    const val = publishedAt ? publishedAt.replace('T', ' ') + ':00' : null;
    editorItem.set({ ...editItem, published_at: val });
  }

  // ─── Folder/Label state ───
  let itemFolders = $state([]);
  let foldersLoading = $state(false);
  let newLabelName = $state('');

  // Load folders and workflow when entering collection-editor
  $effect(() => {
    if (route === 'collection-editor' && editColl && editItem) {
      loadFolders();
      loadWorkflowForCollection();
      loadTransitionHistory();
    }
  });

  async function loadWorkflowForCollection() {
    if (!editColl) return;
    try {
      const data = await workflowsApi.forCollection(editColl.id);
      workflow = data.workflow || null;
    } catch (e) {
      workflow = null;
    }
  }

  async function loadFolders() {
    if (!editColl || !editItem) return;
    foldersLoading = true;
    try {
      const folderData = await foldersApi.list(editColl.id);
      const folders = folderData.folders || [];
      // Get current item's label assignments
      const assignData = await itemLabelsApi.get(editItem.id);
      const assignedIds = (assignData.label_ids || []).map(Number);

      const result = [];
      for (const folder of folders) {
        const labelData = await labelsApi.list(folder.id);
        result.push({
          folder: folder,
          labels: labelData.labels || [],
          selectedIds: new Set(assignedIds.filter(id => (labelData.labels || []).some(t => t.id === id)))
        });
      }
      itemFolders = result;
    } catch (err) {
      console.error('Failed to load folders', err);
    } finally {
      foldersLoading = false;
    }
  }

  async function toggleLabel(folderIndex, labelId) {
    const folder = itemFolders[folderIndex];
    const newSelected = new Set(folder.selectedIds);
    if (newSelected.has(labelId)) {
      newSelected.delete(labelId);
    } else {
      newSelected.add(labelId);
    }
    // Update local state
    itemFolders = itemFolders.map((f, i) =>
      i === folderIndex ? { ...f, selectedIds: newSelected } : f
    );
    // Save to server - collect ALL selected label IDs across all folders
    const allIds = [];
    for (const f of itemFolders) {
      for (const id of (f === folder ? newSelected : f.selectedIds)) {
        allIds.push(id);
      }
    }
    try {
      await itemLabelsApi.set(editItem.id, allIds);
    } catch (err) {
      addToast('Failed to save labels', 'error');
    }
  }

  async function addLabelInline(folderIndex) {
    if (!newLabelName.trim()) return;
    const folder = itemFolders[folderIndex];
    try {
      const slug = newLabelName.trim().toLowerCase().replace(/[^a-z0-9-]/g, '-').replace(/-+/g, '-');
      await labelsApi.create({ taxonomy_id: folder.folder.id, name: newLabelName.trim(), slug });
      newLabelName = '';
      await loadFolders();
    } catch (err) {
      addToast(err.message, 'error');
    }
  }
</script>

<aside class="right-sidebar">
  {#if route === 'collection-editor' && editItem}
    <!-- Tab switcher -->
    <div class="rs-tabs">
      <button class="rs-tab" class:active={sidebarTab === 'post'} onclick={() => sidebarTab = 'post'} title="Post settings" aria-label="Post settings" role="tab" aria-selected={sidebarTab === 'post'}>
        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" width="14" height="14" style="vertical-align:-2px" aria-hidden="true"><path d="M13 2.5l.5.5-8 8H3v-2.5l8-8z"/><path d="M2 13.5h12"/></svg>
      </button>
      <button class="rs-tab" class:active={sidebarTab === 'seo'} onclick={() => sidebarTab = 'seo'} title="SEO" aria-label="SEO settings" role="tab" aria-selected={sidebarTab === 'seo'}>
        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" width="14" height="14" style="vertical-align:-2px" aria-hidden="true"><circle cx="8" cy="8" r="6.5"/><polygon points="8,3 9.5,6.5 13,8 9.5,9.5 8,13 6.5,9.5 3,8 6.5,6.5" stroke="currentColor" fill="none"/></svg>
      </button>
      <button class="rs-tab" class:active={sidebarTab === 'history'} onclick={() => sidebarTab = 'history'} title="Revision history" aria-label="Revision history" role="tab" aria-selected={sidebarTab === 'history'}>
        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" width="14" height="14" style="vertical-align:-2px" aria-hidden="true"><circle cx="8" cy="8" r="6.5"/><polyline points="8 4.5 8 8 10.5 9.5"/></svg>
      </button>
      <button class="rs-tab" class:active={sidebarTab === 'comments'} onclick={() => sidebarTab = 'comments'} title="Comments" aria-label="Comments" role="tab" aria-selected={sidebarTab === 'comments'}>
        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" width="14" height="14" style="vertical-align:-2px" aria-hidden="true"><path d="M14 10a2 2 0 01-2 2H5l-3 3V4a2 2 0 012-2h8a2 2 0 012 2z"/></svg>
      </button>
    </div>

    {#if sidebarTab === 'post'}
      <!-- Post Settings -->
      <div class="sidebar-card">
        <div class="sidebar-card-title">Post Settings</div>

        <!-- Status -->
        <div class="rs-field">
          <label class="rs-label">Status</label>
          {#if getStageBySlug(editItem.status)}
            <div class="rs-status-badge" style="color: {getStageBySlug(editItem.status).color};">
              <span class="rs-status-dot" style="background: {getStageBySlug(editItem.status).color};"></span>
              {getStageBySlug(editItem.status).name}
            </div>
          {:else}
            <div class="rs-status-badge" class:published={editItem.status === 'published'} class:scheduled={editItem.status === 'scheduled'} class:pending-review={editItem.status === 'pending_review'}>
              <span class="rs-status-dot"></span>
              {editItem.status === 'published' ? 'Published' : editItem.status === 'scheduled' ? 'Scheduled' : editItem.status === 'pending_review' ? 'In Review' : 'Draft'}
            </div>
          {/if}
          <!-- Workflow transitions -->
          {#if getAvailableTransitions().length > 0}
            <div class="rs-transitions">
              {#each getAvailableTransitions() as target}
                <button class="rs-transition-btn" onclick={() => transitionTo(target.slug)}>
                  <span class="rs-transition-dot" style="background: {target.color};"></span>
                  Move to {target.name}
                </button>
              {/each}
            </div>
          {/if}
        </div>

        <!-- URL / Slug -->
        <div class="rs-field">
          <label class="rs-label">URL</label>
          {#if slugEditing}
            <input
              class="rs-input rs-slug-input"
              type="text"
              bind:value={slugValue}
              onblur={commitSlug}
              onkeydown={handleSlugKeydown}
              autofocus
            />
          {:else}
            <button class="rs-slug-display" onclick={() => slugEditing = true} title="Click to edit slug">
              <span class="rs-slug-prefix">{editColl ? '/' + editColl.slug + '/' : '/'}</span><span class="rs-slug-editable">{editItem.slug}</span>
              <svg class="rs-slug-edit-icon" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
            </button>
          {/if}
        </div>

        <!-- Excerpt -->
        <div class="rs-field">
          <label class="rs-label">Excerpt</label>
          <textarea
            class="rs-input"
            rows="3"
            bind:value={excerpt}
            oninput={updateExcerpt}
            placeholder="Write a short summary..."
          ></textarea>
        </div>
      </div>

      <!-- Featured Image -->
      <div class="sidebar-card">
        <div class="sidebar-card-title">Featured Image</div>
        {#if featuredImage}
          <div class="rs-featured-wrap">
            <img src={featuredImage} alt="Featured" class="rs-featured-img" />
            <button class="rs-featured-remove" onclick={removeFeaturedImage}>Remove</button>
          </div>
        {:else}
          <label class="rs-featured-upload">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg>
            <span>Add featured image</span>
            <input type="file" accept="image/*" onchange={handleFeaturedUpload} style="display: none;" />
          </label>
        {/if}
      </div>

      <!-- Date / Schedule -->
      <div class="sidebar-card">
        <div class="sidebar-card-title">Date</div>
        {#if editItem.status === 'published'}
          <div class="rs-field">
            <label class="rs-label">Published</label>
            <input
              class="rs-input"
              type="datetime-local"
              bind:value={publishedAt}
              onchange={handlePublishedAtChange}
            />
          </div>
        {:else}
          <div class="rs-field">
            <label class="rs-label">Schedule</label>
            <input
              class="rs-input"
              type="datetime-local"
              bind:value={scheduledAt}
              onchange={handleScheduleChange}
            />
            {#if scheduledAt}
              <button class="btn btn-ghost btn-sm" style="margin-top: var(--space-xs); font-size: 12px;" onclick={clearSchedule}>
                Clear schedule
              </button>
            {/if}
          </div>
        {/if}
      </div>

      <!-- Folders / Labels -->
      {#if itemFolders.length > 0}
        {#each itemFolders as folderEntry, folderIdx}
          <div class="sidebar-card">
            <div class="sidebar-card-title">{folderEntry.folder.name}</div>
            {#if folderEntry.labels.length > 0}
              <div class="rs-tax-terms">
                {#each folderEntry.labels as label}
                  <label class="rs-tax-term">
                    <input
                      type="checkbox"
                      checked={folderEntry.selectedIds.has(label.id)}
                      onchange={() => toggleLabel(folderIdx, label.id)}
                    />
                    <span>{label.name}</span>
                  </label>
                {/each}
              </div>
            {:else}
              <p style="font-size: 12px; color: var(--text-tertiary);">No labels yet</p>
            {/if}
            <div class="rs-tax-add">
              <input
                class="rs-input"
                type="text"
                placeholder="Add new..."
                bind:value={newLabelName}
                onkeydown={(e) => e.key === 'Enter' && addLabelInline(folderIdx)}
                style="font-size: 12px; height: 28px;"
              />
              <button class="btn btn-ghost btn-sm" onclick={() => addLabelInline(folderIdx)} style="flex-shrink: 0;">
                Add
              </button>
            </div>
          </div>
        {/each}
      {/if}

      <!-- Details -->
      <div class="sidebar-card">
        <div class="sidebar-card-title">Details</div>
        {#if editColl}
          <div class="sidebar-card-row">
            <span class="sidebar-card-row-label">Collection</span>
            <span class="sidebar-card-row-value">{editColl.name}</span>
          </div>
        {/if}
        <div class="sidebar-card-row">
          <span class="sidebar-card-row-label">Created</span>
          <span class="sidebar-card-row-value" style="font-size: 12px;">{formatDate(editItem.created_at)}</span>
        </div>
        {#if editItem.updated_at}
          <div class="sidebar-card-row">
            <span class="sidebar-card-row-label">Updated</span>
            <span class="sidebar-card-row-value" style="font-size: 12px;">{formatDate(editItem.updated_at)}</span>
          </div>
        {/if}
        {#if editItem.published_at}
          <div class="sidebar-card-row">
            <span class="sidebar-card-row-label">Published</span>
            <span class="sidebar-card-row-value" style="font-size: 12px;">{formatDate(editItem.published_at)}</span>
          </div>
        {/if}
      </div>

      <!-- Danger Zone -->
      <div class="sidebar-card rs-danger-card">
        {#if confirmDelete}
          <p class="rs-danger-text">Are you sure? This cannot be undone.</p>
          <div style="display: flex; gap: var(--space-xs);">
            <button class="btn btn-sm rs-delete-confirm" onclick={deleteItem}>Delete</button>
            <button class="btn btn-ghost btn-sm" onclick={() => confirmDelete = false}>Cancel</button>
          </div>
        {:else}
          <button class="btn btn-ghost btn-sm rs-delete-btn" onclick={() => confirmDelete = true}>
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/></svg>
            Delete this item
          </button>
        {/if}
      </div>

    {:else if sidebarTab === 'seo'}
      <!-- SEO Score -->
      <div class="sidebar-card">
        <SeoScore
          title={editItem.data?.title || ''}
          metaTitle={metaTitle}
          metaDescription={metaDescription}
          slug={editItem.slug || ''}
          body={editItem.data?.body || ''}
          featuredImage={featuredImage}
        />
      </div>

      <!-- Meta Title -->
      <div class="sidebar-card">
        <div class="rs-field">
          <label class="rs-label">Meta Title</label>
          <input
            class="rs-input"
            type="text"
            bind:value={metaTitle}
            oninput={updateMetaTitle}
            placeholder={editItem.data?.title || 'Page title'}
          />
          <div class="rs-char-count" class:warn={metaTitle.length > 60}>
            {metaTitle.length}/60
          </div>
        </div>
      </div>

      <!-- Meta Description -->
      <div class="sidebar-card">
        <div class="rs-field">
          <label class="rs-label">Meta Description</label>
          <textarea
            class="rs-input"
            rows="4"
            bind:value={metaDescription}
            oninput={updateMetaDescription}
            placeholder="Write a concise description for search results..."
          ></textarea>
          <div class="rs-char-count" class:warn={metaDescription.length > 160}>
            {metaDescription.length}/160
          </div>
        </div>
      </div>

      <!-- Search Preview -->
      <div class="sidebar-card">
        <div class="sidebar-card-title">Search Preview</div>
        <div class="rs-seo-preview">
          <div class="rs-seo-preview-title">{metaTitle || editItem.data?.title || 'Untitled'}</div>
          <div class="rs-seo-preview-url">{getItemUrl()}</div>
          <div class="rs-seo-preview-desc">{metaDescription || excerpt || 'No description set.'}</div>
        </div>
      </div>

    {:else if sidebarTab === 'history'}
      <!-- Workflow Transition History -->
      {#if transitionHistory.length > 0}
        <div class="sidebar-card">
          <div class="sidebar-card-title">Workflow History</div>
          <div class="rs-wf-history">
            {#each transitionHistory as t}
              <div class="rs-wf-entry">
                <div class="rs-wf-entry-main">
                  {#if getStageBySlug(t.from_stage)}
                    <span class="rs-wf-stage-chip" style="color: {getStageBySlug(t.from_stage).color};">{getStageBySlug(t.from_stage).name}</span>
                  {:else if t.from_stage}
                    <span class="rs-wf-stage-chip">{t.from_stage}</span>
                  {/if}
                  <svg width="12" height="8" viewBox="0 0 16 8" fill="none"><path d="M0 4h14M11 1l3 3-3 3" stroke="var(--text-tertiary)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                  {#if getStageBySlug(t.to_stage)}
                    <span class="rs-wf-stage-chip" style="color: {getStageBySlug(t.to_stage).color};">{getStageBySlug(t.to_stage).name}</span>
                  {:else}
                    <span class="rs-wf-stage-chip">{t.to_stage}</span>
                  {/if}
                </div>
                <div class="rs-wf-entry-meta">
                  <span>{t.display_name || t.username || 'System'}</span>
                  <span>&middot;</span>
                  <span>{formatDate(t.created_at)}</span>
                </div>
                {#if t.note}
                  <div class="rs-wf-entry-note">{t.note}</div>
                {/if}
              </div>
            {/each}
          </div>
        </div>
      {/if}

      <!-- Revision History -->
      <div class="sidebar-card">
        <RevisionList
          entityType="item"
          entityId={editItem.id}
          key={$revisionReloadSignal}
          onRestore={() => { editorReloadSignal.update(n => n + 1); sidebarTab = 'post'; }}
        />
      </div>
    {:else if sidebarTab === 'comments'}
      <div class="sidebar-card" style="flex:1;display:flex;flex-direction:column;min-height:0;">
        <Comments entityType="item" entityId={editItem.id} />
      </div>
    {/if}
  {/if}
</aside>

<style>
  /* ─── Post Settings fields ─── */
  .rs-field {
    margin-bottom: var(--space-lg);
  }

  .rs-field:last-child {
    margin-bottom: 0;
  }

  .rs-label {
    display: block;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--text-tertiary);
    margin-bottom: var(--space-xs);
  }

  .rs-input {
    display: block;
    width: 100%;
    font-family: var(--font-sans);
    font-size: 13px;
    padding: 7px 10px;
    border: 1px solid var(--border-primary);
    border-radius: var(--radius-sm);
    background: var(--bg-primary);
    color: var(--text-primary);
    outline: none;
    transition: border-color 0.15s;
  }

  .rs-input:focus {
    border-color: var(--accent);
  }

  textarea.rs-input {
    resize: vertical;
    line-height: 1.5;
  }

  /* ─── Status badge ─── */
  .rs-status-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 13px;
    font-weight: 500;
    color: var(--text-secondary);
    padding: 4px 0;
  }

  .rs-status-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #C49A3D;
  }

  .rs-status-badge.published .rs-status-dot {
    background: #4A8B72;
  }

  .rs-status-badge.scheduled .rs-status-dot {
    background: #5A9BD5;
  }

  .rs-status-badge.pending-review .rs-status-dot {
    background: #D97706;
  }

  /* ─── Slug display ─── */
  .rs-slug-display {
    display: flex;
    align-items: center;
    gap: 4px;
    width: 100%;
    text-align: left;
    background: none;
    border: 1px solid transparent;
    border-radius: var(--radius-sm);
    padding: 6px 8px;
    cursor: pointer;
    font-family: var(--font-mono, monospace);
    font-size: 12px;
    color: var(--text-secondary);
    transition: all 0.15s;
    word-break: break-all;
    line-height: 1.4;
  }

  .rs-slug-display:hover {
    background: var(--bg-hover);
    border-color: var(--border-primary);
  }

  .rs-slug-prefix {
    color: var(--text-tertiary);
  }

  .rs-slug-editable {
    color: var(--text-primary);
    font-weight: 500;
  }

  .rs-slug-edit-icon {
    flex-shrink: 0;
    opacity: 0;
    transition: opacity 0.15s;
    color: var(--text-tertiary);
    margin-left: auto;
  }

  .rs-slug-display:hover .rs-slug-edit-icon {
    opacity: 1;
  }

  .rs-slug-input {
    font-family: var(--font-mono, monospace);
    font-size: 12px;
  }

  /* ─── Featured Image ─── */
  .rs-featured-wrap {
    display: flex;
    flex-direction: column;
    gap: var(--space-xs);
  }

  .rs-featured-img {
    width: 100%;
    height: auto;
    border-radius: var(--radius-sm);
    object-fit: cover;
    max-height: 180px;
  }

  .rs-featured-remove {
    align-self: flex-start;
    background: none;
    border: none;
    font-family: var(--font-sans);
    font-size: 12px;
    color: var(--text-tertiary);
    cursor: pointer;
    padding: 2px 0;
  }

  .rs-featured-remove:hover {
    color: var(--danger);
  }

  .rs-featured-upload {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
    padding: 16px;
    border: 1.5px dashed var(--border-primary);
    border-radius: var(--radius-sm);
    cursor: pointer;
    color: var(--text-tertiary);
    font-size: 13px;
    transition: all 0.15s;
  }

  .rs-featured-upload:hover {
    border-color: var(--accent);
    color: var(--text-secondary);
    background: var(--bg-hover);
  }

  /* ─── Danger zone ─── */
  .rs-danger-card {
    border-color: transparent;
    background: transparent;
    padding: var(--space-md) 0;
  }

  .rs-delete-btn {
    color: var(--text-tertiary);
    font-size: 12px;
    gap: 6px;
    width: 100%;
    justify-content: flex-start;
  }

  .rs-delete-btn:hover {
    color: var(--danger);
  }

  .rs-danger-text {
    font-size: 13px;
    color: var(--danger);
    margin: 0 0 var(--space-sm);
  }

  .rs-delete-confirm {
    background: var(--danger);
    color: white;
    border: none;
  }

  .rs-delete-confirm:hover {
    opacity: 0.9;
  }

  /* ─── Taxonomy selector ─── */
  .rs-tax-terms {
    display: flex;
    flex-direction: column;
    gap: 4px;
    margin-bottom: var(--space-sm);
  }

  .rs-tax-term {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
    color: var(--text-secondary);
    cursor: pointer;
    padding: 2px 0;
  }

  .rs-tax-term input[type="checkbox"] {
    accent-color: var(--accent);
  }

  .rs-tax-add {
    display: flex;
    gap: 4px;
    margin-top: var(--space-xs);
  }

  /* ─── Tab switcher ─── */
  .rs-tabs {
    display: flex;
    gap: 0;
    border-bottom: 1px solid var(--border-primary);
    margin: calc(-1 * var(--space-xl)) calc(-1 * var(--space-xl)) 0;
    padding: 0 var(--space-xl);
  }

  .rs-tab {
    flex: 1;
    background: none;
    border: none;
    border-bottom: 2px solid transparent;
    padding: 10px 0;
    font-family: var(--font-sans);
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--text-tertiary);
    cursor: pointer;
    transition: all 0.15s;
  }

  .rs-tab:hover {
    color: var(--text-secondary);
  }

  .rs-tab.active {
    color: var(--text-primary);
    border-bottom-color: var(--text-primary);
  }

  /* ─── Character count ─── */
  .rs-char-count {
    font-size: 11px;
    color: var(--text-tertiary);
    text-align: right;
    margin-top: 4px;
    font-variant-numeric: tabular-nums;
  }

  .rs-char-count.warn {
    color: var(--warning, #C49A3D);
  }

  /* ─── Search preview ─── */
  .rs-seo-preview {
    padding: 12px 0 0;
  }

  .rs-seo-preview-title {
    font-size: 16px;
    font-weight: 500;
    color: #1a0dab;
    line-height: 1.3;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  :global(.dark) .rs-seo-preview-title {
    color: #8ab4f8;
  }

  .rs-seo-preview-url {
    font-size: 12px;
    color: #006621;
    margin-top: 2px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  :global(.dark) .rs-seo-preview-url {
    color: #bdc1c6;
  }

  .rs-seo-preview-desc {
    font-size: 13px;
    color: var(--text-secondary);
    line-height: 1.5;
    margin-top: 4px;
    display: -webkit-box;
    -webkit-line-clamp: 3;
    -webkit-box-orient: vertical;
    overflow: hidden;
  }

  /* ─── Workflow transitions ─── */
  .rs-transitions {
    display: flex;
    flex-direction: column;
    gap: 4px;
    margin-top: 8px;
  }

  .rs-transition-btn {
    display: flex;
    align-items: center;
    gap: 6px;
    width: 100%;
    padding: 6px 10px;
    border: 1px solid var(--border-primary);
    border-radius: var(--radius-sm);
    background: var(--bg-primary);
    color: var(--text-secondary);
    font-size: 12px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.15s;
    text-align: left;
    font-family: var(--font-sans);
  }

  .rs-transition-btn:hover {
    background: var(--bg-hover);
    border-color: var(--border-secondary, var(--border-primary));
  }

  .rs-transition-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    flex-shrink: 0;
  }

  /* ─── Workflow history ─── */
  .rs-wf-history {
    display: flex;
    flex-direction: column;
    gap: 10px;
  }

  .rs-wf-entry {
    padding-bottom: 10px;
    border-bottom: 1px solid var(--border-primary);
  }

  .rs-wf-entry:last-child {
    border-bottom: none;
    padding-bottom: 0;
  }

  .rs-wf-entry-main {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 13px;
  }

  .rs-wf-stage-chip {
    font-weight: 600;
    font-size: 12px;
  }

  .rs-wf-entry-meta {
    display: flex;
    align-items: center;
    gap: 4px;
    font-size: 11px;
    color: var(--text-tertiary);
    margin-top: 2px;
  }

  .rs-wf-entry-note {
    font-size: 12px;
    color: var(--text-secondary);
    margin-top: 4px;
    font-style: italic;
  }
</style>
