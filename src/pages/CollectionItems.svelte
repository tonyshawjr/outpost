<script>
  import { onMount } from 'svelte';
  import { items as itemsApi, collections as collectionsApi, folders as foldersApi, workflows as workflowsApi, comments as commentsApi } from '$lib/api.js';
  import { currentRoute, currentCollectionSlug, collectionsList, navigate, addToast, currentStatusFilter, isAdmin, user } from '$lib/stores.js';
  import { formatDateOnly } from '$lib/utils.js';
  import EmptyState from '$components/EmptyState.svelte';
  import LabelSidebar from '$components/LabelSidebar.svelte';
  import Checkbox from '$components/Checkbox.svelte';

  let routeName = $derived($currentRoute);
  let activeSlug = $derived($currentCollectionSlug || routeName);
  let colls = $derived($collectionsList);
  let activeColl = $derived(colls.find((c) => c.slug === activeSlug));
  let items = $state([]);
  let loading = $state(true);
  let search = $state('');
  let statusFilter = $derived($currentStatusFilter);

  // Workflow state
  let workflow = $state(null);
  let workflowStages = $derived(workflow?.stages || []);
  let transitionDropdownId = $state(null);

  let creatingItem = $state(false);
  let sortDir = $state('desc'); // 'desc' = newest first

  // ── Comment count badges ──────────────────────────────
  let commentCounts = $state({});

  // ── Label sidebar state ─────────────────────────────────
  let activeLabelId = $state(null);
  let hasFolders = $state(false);
  let dragItemId = $state(null);
  let labelSidebar = $state(null);
  let showLabelMenu = $state(false);
  let labelMenuOptions = $state([]);

  // ── Selection state ────────────────────────────────────
  let selected = $state(new Set());
  let bulkBusy = $state(false);

  let allVisibleSelected = $derived(
    sortedFiltered.length > 0 && sortedFiltered.every(i => selected.has(i.id))
  );

  function toggleSelect(id, e) {
    e.stopPropagation();
    const next = new Set(selected);
    if (next.has(id)) next.delete(id);
    else next.add(id);
    selected = next;
  }

  function toggleSelectAll() {
    if (allVisibleSelected) {
      selected = new Set();
    } else {
      selected = new Set(sortedFiltered.map(i => i.id));
    }
  }

  function clearSelection() {
    selected = new Set();
  }

  async function bulkSetStatus(status) {
    if (selected.size === 0 || bulkBusy) return;
    bulkBusy = true;
    try {
      const ids = [...selected];
      await itemsApi.bulkStatus(ids, status);
      items = items.map(i => ids.includes(i.id)
        ? { ...i, status, ...(status === 'published' && !i.published_at ? { published_at: new Date().toISOString() } : {}) }
        : i
      );
      addToast(`${ids.length} item${ids.length !== 1 ? 's' : ''} set to ${status}`, 'success');
      selected = new Set();
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      bulkBusy = false;
    }
  }

  async function bulkDelete() {
    if (selected.size === 0 || bulkBusy) return;
    const count = selected.size;
    if (!confirm(`Delete ${count} item${count !== 1 ? 's' : ''}? This cannot be undone.`)) return;
    bulkBusy = true;
    try {
      const ids = [...selected];
      await itemsApi.bulkDelete(ids);
      items = items.filter(i => !ids.includes(i.id));
      addToast(`${count} item${count !== 1 ? 's' : ''} deleted`, 'success');
      selected = new Set();
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      bulkBusy = false;
    }
  }

  let adminRole = $derived($isAdmin);
  let showScheduleModal = $state(false);
  let scheduleDate = $state('');
  let scheduleTime = $state('09:00');

  async function bulkApprove() {
    if (selected.size === 0 || bulkBusy) return;
    bulkBusy = true;
    try {
      const ids = [...selected];
      await itemsApi.approve(ids);
      items = items.map(i => ids.includes(i.id)
        ? { ...i, status: 'published', published_at: i.published_at || new Date().toISOString() }
        : i
      );
      addToast(`${ids.length} item${ids.length !== 1 ? 's' : ''} approved`, 'success');
      selected = new Set();
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      bulkBusy = false;
    }
  }

  async function bulkSchedule() {
    if (selected.size === 0 || bulkBusy || !scheduleDate) return;
    bulkBusy = true;
    try {
      const ids = [...selected];
      const scheduledAt = `${scheduleDate}T${scheduleTime}:00`;
      await itemsApi.bulkSchedule(ids, scheduledAt);
      items = items.map(i => ids.includes(i.id)
        ? { ...i, status: 'scheduled', scheduled_at: scheduledAt }
        : i
      );
      addToast(`${ids.length} item${ids.length !== 1 ? 's' : ''} scheduled`, 'success');
      selected = new Set();
      showScheduleModal = false;
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      bulkBusy = false;
    }
  }

  async function bulkWorkflowTransition(toStage) {
    if (selected.size === 0 || bulkBusy) return;
    bulkBusy = true;
    try {
      const ids = [...selected];
      await workflowsApi.bulkTransition(ids, toStage);
      items = items.map(i => ids.includes(i.id)
        ? { ...i, status: toStage, ...(toStage === 'published' && !i.published_at ? { published_at: new Date().toISOString() } : {}) }
        : i
      );
      const stageDef = getStageBySlug(toStage);
      addToast(`${ids.length} item${ids.length !== 1 ? 's' : ''} moved to ${stageDef?.name || toStage}`, 'success');
      selected = new Set();
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      bulkBusy = false;
    }
  }

  function getItemDate(item) {
    return item.status === 'published' ? (item.published_at || item.updated_at) : item.updated_at;
  }

  let filtered = $derived(
    search
      ? items.filter((item) => {
          const title = getItemTitle(item).toLowerCase();
          const sub = getItemSubtitle(item).toLowerCase();
          const s = search.toLowerCase();
          return title.includes(s) || sub.includes(s) || item.slug.toLowerCase().includes(s);
        })
      : items
  );

  let sortedFiltered = $derived(
    [...filtered].sort((a, b) => {
      const da = new Date((getItemDate(a) || '').replace(' ', 'T'));
      const db = new Date((getItemDate(b) || '').replace(' ', 'T'));
      return sortDir === 'desc' ? db - da : da - db;
    })
  );

  onMount(async () => {
    if (colls.length === 0) {
      try {
        const data = await collectionsApi.list();
        collectionsList.set(data.collections || []);
      } catch (e) {}
    }
    if (activeSlug) {
      await loadItems(activeSlug, statusFilter, activeLabelId);
      await checkFolders();
      await loadWorkflow();
      await loadCommentCounts();
    }
  });

  $effect(() => {
    if (activeSlug) {
      loadItems(activeSlug, statusFilter, activeLabelId);
      loadWorkflow();
      loadCommentCounts();
    }
  });

  async function loadWorkflow() {
    if (!activeColl) return;
    try {
      const data = await workflowsApi.forCollection(activeColl.id);
      workflow = data.workflow || null;
    } catch (e) {
      workflow = null;
    }
  }

  function getStageBySlug(slug) {
    return workflowStages.find(s => s.slug === slug) || null;
  }

  function getAvailableTransitions(item) {
    const stage = getStageBySlug(item.status);
    if (!stage) return [];
    const role = $user?.role || '';
    return (stage.can_move_to || [])
      .map(slug => getStageBySlug(slug))
      .filter(s => s && (s.roles || []).includes(role));
  }

  function toggleTransitionDropdown(e, itemId) {
    e.stopPropagation();
    transitionDropdownId = transitionDropdownId === itemId ? null : itemId;
  }

  async function transitionItem(e, item, toStage) {
    e.stopPropagation();
    transitionDropdownId = null;
    try {
      await workflowsApi.transition(item.id, toStage);
      items = items.map(i => i.id === item.id
        ? { ...i, status: toStage, ...(toStage === 'published' && !i.published_at ? { published_at: new Date().toISOString() } : {}) }
        : i
      );
      const stageDef = getStageBySlug(toStage);
      addToast(`Moved to ${stageDef?.name || toStage}`, 'success');
    } catch (err) {
      addToast(err.message, 'error');
    }
  }

  // Close dropdown on outside click
  $effect(() => {
    if (transitionDropdownId === null) return;
    function close() { transitionDropdownId = null; }
    document.addEventListener('click', close);
    return () => document.removeEventListener('click', close);
  });

  async function loadCommentCounts() {
    if (!activeColl) return;
    try {
      const data = await commentsApi.count({ collection_id: activeColl.id });
      commentCounts = data.counts || {};
    } catch (e) {
      commentCounts = {};
    }
  }

  async function checkFolders() {
    if (!activeColl) return;
    try {
      const data = await foldersApi.list(activeColl.id);
      const folders = data.folders || [];
      hasFolders = folders.length > 0;
    } catch (e) {
      hasFolders = false;
    }
  }

  async function loadItems(slug, status = 'all', labelId = null) {
    loading = true;
    selected = new Set();
    try {
      const labelParam = labelId != null ? String(labelId) : '';
      const data = await itemsApi.list(slug, status !== 'all' ? status : '', labelParam);
      items = data.items || [];
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      loading = false;
    }
  }

  function getSchema() {
    if (!activeColl) return {};
    try { return JSON.parse(activeColl.schema || '{}'); } catch { return {}; }
  }

  function getItemTitle(item) {
    const d = item.data || {};
    return d.title || d.name || d.heading || item.slug;
  }

  function getItemSubtitle(item) {
    const d = item.data || {};
    const s = getSchema();
    const keys = Object.keys(s).filter(k => k !== 'title' && k !== 'name' && k !== 'heading');
    for (const k of keys) {
      if (d[k] && typeof d[k] === 'string' && d[k].length > 0 && s[k]?.type !== 'image' && s[k]?.type !== 'toggle') {
        const text = d[k].replace(/<[^>]*>/g, '').trim();
        if (text.length > 0) return text.length > 120 ? text.slice(0, 120) + '...' : text;
      }
    }
    return '/' + item.slug + '/';
  }

  async function createNewItem() {
    if (!activeSlug || !activeColl || creatingItem) return;

    if (activeSlug === 'pages') {
      navigate('page-builder', { pageId: 0 });
      return;
    }

    creatingItem = true;
    try {
      const timestamp = Date.now().toString(36);
      const slug = `untitled-${timestamp}`;
      const result = await itemsApi.create({
        collection_id: activeColl.id,
        slug,
        data: { title: '' },
        status: 'draft',
      });
      const newId = result.item?.id || result.id;
      if (newId) {
        navigate('collection-editor', { itemId: newId, collectionSlug: activeSlug });
      }
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      creatingItem = false;
    }
  }

  async function toggleStatus(e, item) {
    e.stopPropagation();
    const newStatus = item.status === 'published' ? 'draft' : 'published';
    try {
      await itemsApi.update(item.id, { status: newStatus });
      items = items.map((i) =>
        i.id === item.id ? { ...i, status: newStatus } : i
      );
    } catch (err) {
      addToast(err.message, 'error');
    }
  }

  async function deleteItem(e, item) {
    e.stopPropagation();
    if (!confirm(`Delete "${getItemTitle(item)}"?`)) return;
    try {
      await itemsApi.delete(item.id);
      items = items.filter((i) => i.id !== item.id);
      addToast('Deleted', 'success');
    } catch (err) {
      addToast(err.message, 'error');
    }
  }

  function editItem(item) {
    if (activeSlug === 'pages') {
      navigate('page-builder', { pageId: item.id });
    } else {
      navigate('collection-editor', { itemId: item.id, collectionSlug: activeSlug });
    }
  }

  function setStatusFilter(status) {
    navigate('collection-items', { collectionSlug: activeSlug, statusFilter: status });
  }

  // ── Drag-to-label ──────────────────────────────────────
  function onItemDragStart(e, item) {
    dragItemId = item.id;
    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('text/plain', String(item.id));
  }

  async function handleLabelDrop(labelId, itemIds) {
    try {
      await itemsApi.bulkAssignLabels(itemIds, labelId, 'add');
      addToast('Label assigned', 'success');
      if (labelSidebar) labelSidebar.loadLabels();
      if (activeLabelId != null) await loadItems(activeSlug, statusFilter, activeLabelId);
    } catch (err) {
      addToast(err.message, 'error');
    }
  }

  // ── Bulk label assign ──────────────────────────────────
  // Close label dropdown on outside click
  $effect(() => {
    if (!showLabelMenu) return;
    function handleClick() { showLabelMenu = false; }
    document.addEventListener('click', handleClick);
    return () => document.removeEventListener('click', handleClick);
  });

  async function loadLabelMenuOptions() {
    try {
      const data = await itemsApi.labelsWithCounts(activeSlug);
      const allLabels = [];
      for (const folder of (data.folders || [])) {
        for (const label of (folder.labels || [])) {
          allLabels.push({ id: label.id, name: label.name, folderName: folder.name });
        }
      }
      labelMenuOptions = allLabels;
      showLabelMenu = true;
    } catch (err) {
      addToast(err.message, 'error');
    }
  }

  async function bulkAssignLabel(labelId) {
    if (selected.size === 0 || bulkBusy) return;
    bulkBusy = true;
    showLabelMenu = false;
    try {
      await itemsApi.bulkAssignLabels([...selected], labelId, 'add');
      addToast(`Label assigned to ${selected.size} item${selected.size !== 1 ? 's' : ''}`, 'success');
      selected = new Set();
      if (labelSidebar) labelSidebar.loadLabels();
      if (activeLabelId != null) await loadItems(activeSlug, statusFilter, activeLabelId);
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      bulkBusy = false;
    }
  }
</script>

<div class="ghost-list-view" class:has-label-sidebar={hasFolders}>
  <!-- Page Header -->
  <div class="page-header">
    <div class="page-header-icon clay">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M22 19a2 2 0 01-2 2H4a2 2 0 01-2-2V5a2 2 0 012-2h5l2 3h9a2 2 0 012 2z"/></svg>
    </div>
    <div class="page-header-content">
      <h1 class="page-title">{activeColl?.name || 'Collection'}</h1>
      <p class="page-subtitle">
        {items.length} {items.length === 1 ? (activeColl?.singular_name || 'item') : (activeColl?.name || 'items').toLowerCase()}
      </p>
    </div>
    <div class="page-header-actions">
      <div class="search-input header-search">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <input
          class="input"
          type="text"
          placeholder="Search {(activeColl?.name || 'items').toLowerCase()}..."
          bind:value={search}
        />
      </div>
      <button class="btn btn-primary" onclick={createNewItem}>
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        New {activeColl?.singular_name || 'Item'}
      </button>
    </div>
  </div>

  <!-- Bulk Action Bar -->
  {#if selected.size > 0}
    <div class="bulk-bar">
      <span class="bulk-count">{selected.size} selected</span>
      <div class="bulk-actions">
        {#if adminRole && statusFilter === 'pending_review'}
          <button class="btn btn-secondary btn-sm" onclick={bulkApprove} disabled={bulkBusy}>
            Approve
          </button>
        {/if}
        {#if workflowStages.length > 2}
          {#each workflowStages as stage}
            <button class="btn btn-secondary btn-sm" onclick={() => bulkWorkflowTransition(stage.slug)} disabled={bulkBusy}>
              <span class="wf-bulk-dot" style="background: {stage.color};"></span>
              {stage.name}
            </button>
          {/each}
        {:else}
          <button class="btn btn-secondary btn-sm" onclick={() => bulkSetStatus('published')} disabled={bulkBusy}>
            Publish
          </button>
          <button class="btn btn-secondary btn-sm" onclick={() => bulkSetStatus('draft')} disabled={bulkBusy}>
            Unpublish
          </button>
        {/if}
        <button class="btn btn-secondary btn-sm" onclick={() => { scheduleDate = ''; showScheduleModal = true; }} disabled={bulkBusy}>
          Schedule
        </button>
        {#if hasFolders}
          <div class="bulk-label-wrapper">
            <button class="btn btn-secondary btn-sm" onclick={loadLabelMenuOptions} disabled={bulkBusy}>
              Label
            </button>
            {#if showLabelMenu}
              <div class="label-dropdown">
                {#each labelMenuOptions as opt (opt.id)}
                  <button class="label-dropdown-item" onclick={() => bulkAssignLabel(opt.id)}>
                    {opt.name}
                  </button>
                {/each}
                {#if labelMenuOptions.length === 0}
                  <div class="label-dropdown-empty">No labels yet</div>
                {/if}
              </div>
            {/if}
          </div>
        {/if}
        <button class="btn btn-secondary btn-sm btn-danger-text" onclick={bulkDelete} disabled={bulkBusy}>
          Delete
        </button>
      </div>
      <button class="bulk-clear" onclick={clearSelection}>
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>
  {/if}

  <!-- Active Filter Pill -->
  {#if statusFilter !== 'all'}
    <div class="filter-pill-bar">
      <span class="filter-pill">
        <span class="filter-pill-dot" class:draft={statusFilter === 'draft'} class:scheduled={statusFilter === 'scheduled'} class:published={statusFilter === 'published'} class:pending={statusFilter === 'pending_review'}></span>
        {statusFilter === 'draft' ? 'Drafts' : statusFilter === 'scheduled' ? 'Scheduled' : statusFilter === 'pending_review' ? 'Pending Review' : 'Published'}
      </span>
      <button class="filter-clear" onclick={() => setStatusFilter('all')}>
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        Show all
      </button>
    </div>
  {/if}

  <div class="collection-content-area" class:collection-layout-2col={hasFolders}>
    {#if hasFolders}
      <LabelSidebar
        bind:this={labelSidebar}
        collectionId={activeColl?.id}
        collectionSlug={activeSlug}
        bind:activeLabelId
        onLabelDrop={handleLabelDrop}
      />
    {/if}

    <div class="collection-main">
      {#if loading}
        <div class="loading-overlay"><div class="spinner"></div></div>
      {:else if items.length === 0 && activeLabelId === null}
        <EmptyState
          icon='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1"><path d="M22 19a2 2 0 01-2 2H4a2 2 0 01-2-2V5a2 2 0 012-2h5l2 3h9a2 2 0 012 2z"/></svg>'
          title="No {activeColl?.name?.toLowerCase() || 'items'} yet"
          description="Create your first {activeColl?.singular_name?.toLowerCase() || 'item'} to get started."
          ctaLabel="Create {activeColl?.singular_name || 'Item'}"
          ctaAction={createNewItem}
        />
      {:else if items.length === 0 && activeLabelId !== null}
        <EmptyState
          title="No items in this label"
          description="Drag items here or use bulk actions to assign labels."
        />
      {:else}
        <!-- Column Headers -->
        <div class="list-col-headers">
          <div class="col-h-check" onclick={(e) => e.stopPropagation()}>
            <Checkbox checked={allVisibleSelected} onchange={toggleSelectAll} />
          </div>
          <span class="col-h-left">TITLE</span>
          <div class="col-h-right">
            <span class="col-h-status">STATUS</span>
            <button class="sort-btn" onclick={() => sortDir = sortDir === 'desc' ? 'asc' : 'desc'}>
              DATE
              <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                {#if sortDir === 'desc'}
                  <polyline points="6 9 12 15 18 9"/>
                {:else}
                  <polyline points="18 15 12 9 6 15"/>
                {/if}
              </svg>
            </button>
            <span class="col-h-spacer"></span>
          </div>
        </div>

        <!-- Rows -->
        <div class="list-rows">
          {#each sortedFiltered as item (item.id)}
            <div
              class="list-row"
              class:selected={selected.has(item.id)}
              onclick={() => editItem(item)}
              role="button"
              tabindex="0"
              onkeydown={(e) => e.key === 'Enter' && editItem(item)}
              draggable={hasFolders ? 'true' : undefined}
              ondragstart={(e) => hasFolders && onItemDragStart(e, item)}
              ondragend={() => dragItemId = null}
            >
              <div class="row-check" onclick={(e) => e.stopPropagation()}>
                <Checkbox checked={selected.has(item.id)} onchange={() => { const next = new Set(selected); if (next.has(item.id)) next.delete(item.id); else next.add(item.id); selected = next; }} />
              </div>
              <div class="list-row-left">
                <div class="list-row-title">{getItemTitle(item)}</div>
                <div class="list-row-subtitle">{getItemSubtitle(item)}</div>
              </div>
              <div class="list-row-right">
                <div class="wf-status-wrap">
                  <button
                    class="status-badge wf-status-badge"
                    style={getStageBySlug(item.status) ? `background: ${getStageBySlug(item.status).color}20; color: ${getStageBySlug(item.status).color};` : ''}
                    class:status-published={!getStageBySlug(item.status) && item.status === 'published'}
                    class:status-draft={!getStageBySlug(item.status) && item.status === 'draft'}
                    class:status-scheduled={!getStageBySlug(item.status) && item.status === 'scheduled'}
                    class:status-pending={!getStageBySlug(item.status) && item.status === 'pending_review'}
                    onclick={(e) => getAvailableTransitions(item).length > 0 ? toggleTransitionDropdown(e, item.id) : toggleStatus(e, item)}
                    aria-label="Change status"
                  >
                    {getStageBySlug(item.status) ? getStageBySlug(item.status).name : (item.status === 'pending_review' ? 'in review' : item.status)}
                  </button>
                  {#if transitionDropdownId === item.id && getAvailableTransitions(item).length > 0}
                    <div class="wf-transition-dropdown" onclick={(e) => e.stopPropagation()}>
                      {#each getAvailableTransitions(item) as target}
                        <button class="wf-transition-item" onclick={(e) => transitionItem(e, item, target.slug)}>
                          <span class="wf-transition-dot" style="background: {target.color};"></span>
                          {target.name}
                        </button>
                      {/each}
                    </div>
                  {/if}
                </div>
                {#if commentCounts[item.id]}
                  <span class="comment-badge" title="{commentCounts[item.id]} open comment{commentCounts[item.id] !== 1 ? 's' : ''}">
                    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" width="12" height="12">
                      <path d="M14 10a2 2 0 01-2 2H5l-3 3V4a2 2 0 012-2h8a2 2 0 012 2z"/>
                    </svg>
                    {commentCounts[item.id]}
                  </span>
                {/if}
                <span class="list-row-time">{formatDateOnly(getItemDate(item))}</span>
                <button
                  class="list-row-delete"
                  onclick={(e) => deleteItem(e, item)}
                  title="Delete"
                  aria-label="Delete item"
                >
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="15" height="15"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg>
                </button>
              </div>
            </div>
          {/each}
        </div>

        {#if search && filtered.length === 0}
          <EmptyState searchActive={true} />
        {/if}
      {/if}
    </div>
  </div>
</div>

<!-- Schedule Modal -->
{#if showScheduleModal}
  <div class="schedule-overlay" onclick={() => showScheduleModal = false} role="presentation">
    <div class="schedule-modal" onclick={(e) => e.stopPropagation()} role="dialog">
      <h3 style="margin: 0 0 16px; font-size: 15px; font-weight: 600;">Schedule {selected.size} item{selected.size !== 1 ? 's' : ''}</h3>
      <div style="display: flex; gap: 8px; margin-bottom: 16px;">
        <input type="date" class="input" bind:value={scheduleDate} min={new Date().toISOString().split('T')[0]} style="flex: 1;" />
        <input type="time" class="input" bind:value={scheduleTime} style="flex: 0 0 110px;" />
      </div>
      <div style="display: flex; gap: 8px; justify-content: flex-end;">
        <button class="btn btn-secondary btn-sm" onclick={() => showScheduleModal = false}>Cancel</button>
        <button class="btn btn-primary btn-sm" onclick={bulkSchedule} disabled={!scheduleDate || bulkBusy}>
          {bulkBusy ? 'Scheduling...' : 'Schedule'}
        </button>
      </div>
    </div>
  </div>
{/if}

<style>
  .ghost-list-view {
    max-width: var(--content-width);
  }

  /* ── Column Headers ───────────────────────────────── */
  .list-col-headers {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 10px 28px;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--dim);
    border-bottom: 1px solid var(--border);
    user-select: none;
  }

  /* Mirror .list-row-left */
  .col-h-left {
    flex: 1;
    min-width: 0;
  }

  /* Mirror .list-row-right */
  .col-h-right {
    display: flex;
    align-items: center;
    gap: var(--space-md);
    flex-shrink: 0;
    margin-left: var(--space-xl);
  }

  /* Mirror status-badge width */
  .col-h-status {
    width: 88px;
    text-align: center;
  }

  /* Mirror delete button space (15px icon) */
  .col-h-spacer {
    width: 15px;
  }

  .sort-btn {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    background: none;
    border: none;
    font-family: var(--font);
    font-size: 11px;
    font-weight: 600;
    letter-spacing: 0.05em;
    color: var(--dim);
    cursor: pointer;
    padding: 0;
    text-transform: uppercase;
    white-space: nowrap;
  }
  .sort-btn:hover { color: var(--text); }

  /* ── Checkbox ──────────────────────────────────────── */
  .col-h-check,
  .row-check {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    flex-shrink: 0;
    cursor: pointer;
  }

  .bulk-checkbox {
    width: 15px;
    height: 15px;
    accent-color: var(--text);
    cursor: pointer;
    margin: 0;
    opacity: 0.35;
    transition: opacity 0.1s;
  }

  .col-h-check .bulk-checkbox,
  .list-row:hover .bulk-checkbox,
  .list-row.selected .bulk-checkbox,
  .bulk-checkbox:checked {
    opacity: 1;
  }

  /* ── Bulk Action Bar ───────────────────────────────── */
  .bulk-bar {
    display: flex;
    align-items: center;
    gap: var(--space-md);
    padding: var(--space-sm) var(--space-lg);
    background: var(--hover);
    border-bottom: 1px solid var(--border);
  }

  .bulk-count {
    font-size: 13px;
    font-weight: 600;
    color: var(--text);
    white-space: nowrap;
  }

  .bulk-actions {
    display: flex;
    gap: 6px;
  }

  .btn-sm {
    font-size: 12px;
    padding: 4px 12px;
  }

  .btn-danger-text {
    color: var(--error, #e53e3e);
  }

  .btn-danger-text:hover {
    background: rgba(229, 62, 62, 0.08);
    color: var(--error, #e53e3e);
  }

  .bulk-clear {
    display: flex;
    align-items: center;
    justify-content: center;
    background: none;
    border: none;
    color: var(--dim);
    cursor: pointer;
    padding: 4px;
    border-radius: var(--radius-sm);
    margin-left: auto;
    transition: color 0.1s;
  }

  .bulk-clear:hover {
    color: var(--text);
  }

  /* ── Rows ─────────────────────────────────────────── */
  .list-rows {
    display: flex;
    flex-direction: column;
  }

  .list-row {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 14px 28px;
    cursor: pointer;
    transition: background .08s;
    border-bottom: 1px solid var(--border);
  }

  .list-row:hover {
    background: var(--hover);
  }

  .list-row.selected {
    background: var(--hover);
  }

  .list-row-left {
    flex: 1;
    min-width: 0;
    display: flex;
    align-items: center;
    gap: 10px;
  }

  .list-row-title {
    font-weight: 500;
    font-size: 14px;
    color: var(--text);
  }

  .list-row-subtitle {
    font-size: 13px;
    color: var(--dim);
    white-space: nowrap;
  }

  .list-row-right {
    display: flex;
    align-items: center;
    gap: var(--space-md);
    flex-shrink: 0;
    margin-left: var(--space-xl);
  }

  .comment-badge {
    display: inline-flex;
    align-items: center;
    gap: 3px;
    font-size: 12px;
    color: var(--dim);
    white-space: nowrap;
    padding: 2px 6px;
    border-radius: 4px;
    transition: color 0.1s, background 0.1s;
  }

  .list-row:hover .comment-badge {
    color: var(--sec);
    background: var(--hover);
  }

  .list-row-time {
    font-size: var(--font-size-xs);
    color: var(--dim);
    white-space: nowrap;
  }

  /* ── Status Badge ─────────────────────────────────── */
  .status-badge {
    display: inline-flex;
    align-items: center;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    padding: 3px 10px;
    border-radius: 4px;
    border: none;
    cursor: pointer;
    transition: opacity var(--transition-fast);
    line-height: 1.4;
  }

  .status-badge:hover {
    opacity: 0.8;
  }

  .status-published {
    background: rgba(91, 140, 90, 0.15);
    color: var(--success);
  }

  .status-draft {
    background: rgba(196, 154, 61, 0.15);
    color: var(--warning);
  }

  /* ── Search in header ─────────────────────────────── */
  .header-search {
    min-width: 200px;
  }

  .header-search .input {
    font-size: var(--font-size-sm);
  }

  .page-header-actions {
    gap: var(--space-sm);
  }

  /* ── Filter Pill ────────────────────────────────── */
  .filter-pill-bar {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
    padding: var(--space-sm) var(--space-lg);
    margin-bottom: var(--space-sm);
  }

  .filter-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 12px;
    border-radius: 99px;
    font-size: 13px;
    font-weight: 500;
    background: var(--hover);
    color: var(--text);
    border: 1px solid var(--border);
  }

  .filter-pill-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
  }

  .filter-pill-dot.draft { background: #C49A3D; }
  .filter-pill-dot.scheduled { background: #5A9BD5; }
  .filter-pill-dot.published { background: #5B8C5A; }
  .filter-pill-dot.pending { background: #D97706; }

  .filter-clear {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    background: none;
    border: none;
    font-size: 13px;
    color: var(--dim);
    cursor: pointer;
    padding: 2px 4px;
    border-radius: var(--radius-sm);
    transition: color 0.1s;
  }

  .filter-clear:hover {
    color: var(--text);
  }

  .status-scheduled {
    background: rgba(90, 155, 213, 0.15);
    color: #5A9BD5;
  }

  .status-pending {
    background: rgba(217, 119, 6, 0.15);
    color: #D97706;
  }

  .list-row-delete {
    display: flex;
    align-items: center;
    justify-content: center;
    background: none;
    border: none;
    color: var(--text-muted);
    cursor: pointer;
    padding: 4px;
    border-radius: var(--radius-sm);
    opacity: 0;
    transition: opacity 0.1s, color 0.1s;
    flex-shrink: 0;
  }

  .list-row:hover .list-row-delete {
    opacity: 0.45;
  }

  .list-row-delete:hover {
    opacity: 1 !important;
    color: var(--error, #e53e3e);
  }

  .schedule-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.35);
    z-index: 1000;
    display: flex;
    align-items: center;
    justify-content: center;
  }

  .schedule-modal {
    background: var(--bg-primary, #fff);
    border-radius: 8px;
    padding: 24px;
    width: 340px;
    max-width: 90vw;
    box-shadow: 0 8px 30px rgba(0,0,0,0.12);
  }

  .schedule-modal h3 {
    margin: 0 0 16px;
    font-size: 15px;
    font-weight: 600;
  }

  .schedule-modal label {
    display: block;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--text-muted);
    margin-bottom: 4px;
  }

  .schedule-modal input[type="date"],
  .schedule-modal input[type="time"] {
    width: 100%;
    padding: 6px 8px;
    border: 1px solid var(--border-light, #e2e8f0);
    border-radius: 4px;
    font-size: 13px;
    margin-bottom: 12px;
    background: var(--bg-primary, #fff);
    color: var(--text);
  }

  .schedule-modal-actions {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    margin-top: 4px;
  }

  /* ── 2-Column Layout ────────────────────────────────── */
  .collection-content-area {
    /* Single column by default */
  }

  .collection-layout-2col {
    display: flex;
    gap: var(--space-xl);
  }

  .collection-main {
    flex: 1;
    min-width: 0;
  }

  .has-label-sidebar {
    max-width: var(--content-width-wide);
  }

  /* ── Bulk Label Dropdown ───────────────────────────── */
  .bulk-label-wrapper {
    position: relative;
  }

  .label-dropdown {
    position: absolute;
    top: 100%;
    left: 0;
    margin-top: 4px;
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    box-shadow: 0 4px 16px rgba(0,0,0,0.12);
    min-width: 160px;
    max-height: 200px;
    overflow-y: auto;
    z-index: 100;
  }

  .label-dropdown-item {
    display: block;
    width: 100%;
    padding: 8px 14px;
    border: none;
    background: none;
    font-size: 13px;
    color: var(--text);
    text-align: left;
    cursor: pointer;
    transition: background 0.1s;
  }
  .label-dropdown-item:hover {
    background: var(--hover);
  }

  .label-dropdown-empty {
    padding: 8px 14px;
    font-size: 12px;
    color: var(--dim);
  }

  /* ── Workflow status & transitions ──────────────────── */
  .wf-status-wrap {
    position: relative;
  }

  .wf-status-badge {
    min-width: 70px;
    text-align: center;
  }

  .wf-transition-dropdown {
    position: absolute;
    top: 100%;
    right: 0;
    margin-top: 4px;
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    box-shadow: 0 4px 16px rgba(0,0,0,0.12);
    min-width: 140px;
    z-index: 100;
    overflow: hidden;
  }

  .wf-transition-item {
    display: flex;
    align-items: center;
    gap: 6px;
    width: 100%;
    padding: 8px 14px;
    border: none;
    background: none;
    font-size: 13px;
    color: var(--text);
    text-align: left;
    cursor: pointer;
    transition: background 0.1s;
  }

  .wf-transition-item:hover {
    background: var(--hover);
  }

  .wf-transition-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    flex-shrink: 0;
  }

  .wf-bulk-dot {
    display: inline-block;
    width: 6px;
    height: 6px;
    border-radius: 50%;
    margin-right: 2px;
    vertical-align: middle;
  }

  @media (max-width: 768px) {
    .list-row-subtitle {
      max-width: 100%;
    }

    .header-search {
      min-width: 0;
      width: 100%;
    }

    .page-header-actions {
      flex-wrap: wrap;
    }

    .bulk-bar {
      flex-wrap: wrap;
    }

    .list-row {
      flex-wrap: wrap;
    }

    .list-row-right {
      margin-left: 0;
      flex-wrap: wrap;
      gap: var(--space-sm);
    }

    .filter-pill-bar {
      flex-wrap: wrap;
    }

    .collection-layout-2col {
      flex-direction: column;
    }

    :global(.collection-layout-2col .folder-sidebar) {
      width: 100%;
      display: flex;
      flex-wrap: wrap;
      gap: 4px;
    }
  }
</style>
