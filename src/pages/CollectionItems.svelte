<script>
  import { onMount } from 'svelte';
  import { items as itemsApi, collections as collectionsApi } from '$lib/api.js';
  import { currentCollectionSlug, collectionsList, navigate, addToast, currentStatusFilter, isAdmin } from '$lib/stores.js';
  import { formatDateOnly } from '$lib/utils.js';
  import EmptyState from '$components/EmptyState.svelte';

  let activeSlug = $derived($currentCollectionSlug);
  let colls = $derived($collectionsList);
  let activeColl = $derived(colls.find((c) => c.slug === activeSlug));
  let items = $state([]);
  let loading = $state(true);
  let search = $state('');
  let statusFilter = $derived($currentStatusFilter);

  let creatingItem = $state(false);
  let sortDir = $state('desc'); // 'desc' = newest first

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
    if (activeSlug) await loadItems(activeSlug, statusFilter);
  });

  $effect(() => {
    if (activeSlug) loadItems(activeSlug, statusFilter);
  });

  async function loadItems(slug, status = 'all') {
    loading = true;
    selected = new Set();
    try {
      const data = await itemsApi.list(slug, status !== 'all' ? status : '');
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
    creatingItem = true;
    try {
      // Auto-generate a unique slug
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
    navigate('collection-editor', { itemId: item.id, collectionSlug: activeSlug });
  }

  function setStatusFilter(status) {
    navigate('collection-items', { collectionSlug: activeSlug, statusFilter: status });
  }
</script>

<div class="ghost-list-view">
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
        <button class="btn btn-secondary btn-sm" onclick={() => bulkSetStatus('published')} disabled={bulkBusy}>
          Publish
        </button>
        <button class="btn btn-secondary btn-sm" onclick={() => bulkSetStatus('draft')} disabled={bulkBusy}>
          Unpublish
        </button>
        <button class="btn btn-secondary btn-sm" onclick={() => { scheduleDate = ''; showScheduleModal = true; }} disabled={bulkBusy}>
          Schedule
        </button>
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

  {#if loading}
    <div class="loading-overlay"><div class="spinner"></div></div>
  {:else if items.length === 0}
    <EmptyState
      icon='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1"><path d="M22 19a2 2 0 01-2 2H4a2 2 0 01-2-2V5a2 2 0 012-2h5l2 3h9a2 2 0 012 2z"/></svg>'
      title="No {activeColl?.name?.toLowerCase() || 'items'} yet"
      description="Create your first {activeColl?.singular_name?.toLowerCase() || 'item'} to get started."
      ctaLabel="Create {activeColl?.singular_name || 'Item'}"
      ctaAction={createNewItem}
    />
  {:else}
    <!-- Column Headers -->
    <div class="list-col-headers">
      <label class="col-h-check" onclick={(e) => e.stopPropagation()}>
        <input
          type="checkbox"
          checked={allVisibleSelected}
          onchange={toggleSelectAll}
          class="bulk-checkbox"
        />
      </label>
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
        >
          <label class="row-check" onclick={(e) => e.stopPropagation()}>
            <input
              type="checkbox"
              checked={selected.has(item.id)}
              onchange={(e) => toggleSelect(item.id, e)}
              class="bulk-checkbox"
            />
          </label>
          <div class="list-row-left">
            <div class="list-row-title">{getItemTitle(item)}</div>
            <div class="list-row-subtitle">{getItemSubtitle(item)}</div>
          </div>
          <div class="list-row-right">
            <button
              class="status-badge"
              class:status-published={item.status === 'published'}
              class:status-draft={item.status === 'draft'}
              class:status-scheduled={item.status === 'scheduled'}
              class:status-pending={item.status === 'pending_review'}
              onclick={(e) => toggleStatus(e, item)}
              aria-label="Toggle status"
            >
              {item.status === 'pending_review' ? 'in review' : item.status}
            </button>
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
    padding: var(--space-sm) var(--space-lg);
    font-size: 11px;
    font-weight: 600;
    letter-spacing: 0.05em;
    color: var(--text-tertiary);
    border-bottom: 1px solid var(--border-primary);
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
    font-family: var(--font-sans);
    font-size: 11px;
    font-weight: 600;
    letter-spacing: 0.05em;
    color: var(--text-tertiary);
    cursor: pointer;
    padding: 0;
    text-transform: uppercase;
    white-space: nowrap;
  }
  .sort-btn:hover { color: var(--text-primary); }

  /* ── Checkbox ──────────────────────────────────────── */
  .col-h-check,
  .row-check {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    flex-shrink: 0;
    cursor: pointer;
  }

  .bulk-checkbox {
    width: 15px;
    height: 15px;
    accent-color: var(--text-primary);
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
    background: var(--bg-tertiary);
    border-bottom: 1px solid var(--border-primary);
  }

  .bulk-count {
    font-size: 13px;
    font-weight: 600;
    color: var(--text-primary);
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
    color: var(--text-tertiary);
    cursor: pointer;
    padding: 4px;
    border-radius: var(--radius-sm);
    margin-left: auto;
    transition: color 0.1s;
  }

  .bulk-clear:hover {
    color: var(--text-primary);
  }

  /* ── Rows ─────────────────────────────────────────── */
  .list-rows {
    display: flex;
    flex-direction: column;
  }

  .list-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: var(--space-md) var(--space-lg);
    cursor: pointer;
    transition: background var(--transition-fast);
    border-radius: 0;
  }

  .list-row:hover {
    background: var(--bg-hover);
  }

  .list-row.selected {
    background: var(--bg-tertiary);
  }

  .list-row-left {
    flex: 1;
    min-width: 0;
  }

  .list-row-title {
    font-weight: 600;
    font-size: 15px;
    color: var(--text-primary);
    line-height: 1.3;
  }

  .list-row-subtitle {
    font-size: var(--font-size-sm);
    color: var(--text-tertiary);
    margin-top: 2px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 600px;
  }

  .list-row-right {
    display: flex;
    align-items: center;
    gap: var(--space-md);
    flex-shrink: 0;
    margin-left: var(--space-xl);
  }

  .list-row-time {
    font-size: var(--font-size-xs);
    color: var(--text-tertiary);
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
    background: var(--bg-tertiary);
    color: var(--text-primary);
    border: 1px solid var(--border-primary);
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
    color: var(--text-tertiary);
    cursor: pointer;
    padding: 2px 4px;
    border-radius: var(--radius-sm);
    transition: color 0.1s;
  }

  .filter-clear:hover {
    color: var(--text-primary);
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
    color: var(--text-primary);
  }

  .schedule-modal-actions {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    margin-top: 4px;
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
  }
</style>
