<script>
  import { onMount } from 'svelte';
  import { forms as formsApi, formBuilder } from '$lib/api.js';
  import { addToast } from '$lib/stores.js';

  let formsList      = $state([]);
  let builderForms   = $state([]);
  let submissions    = $state([]);
  let selected       = $state(null);
  let filter         = $state('');
  let filterFormId   = $state('');
  let statusFilter   = $state('active');
  let page           = $state(1);
  let totalPages     = $state(1);
  let totalCount     = $state(0);
  let loading        = $state(true);
  let loadingSubs    = $state(false);
  let selectedIds    = $state(new Set());
  let notesDraft     = $state('');
  let editingNotes   = $state(false);

  // Per-form notification email config
  let notifyEmail    = $state('');
  let editingNotify  = $state(false);
  let notifyDraft    = $state('');
  let savingNotify   = $state(false);
  let _configCache   = {};

  // Mobile navigation state
  let mobileView     = $state('list'); // 'list' or 'detail'

  onMount(async () => {
    loading = true;
    try {
      const [data, cfgData, bData] = await Promise.all([
        formsApi.list(),
        formsApi.getConfig('').catch(() => ({ configs: {} })),
        formBuilder.list().catch(() => ({ forms: [] })),
      ]);
      formsList      = data.forms || [];
      totalCount     = formsList.reduce((s, f) => s + (f.total || 0), 0);
      _configCache   = cfgData.configs || {};
      builderForms   = bData.forms || [];
      await loadSubs();
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      loading = false;
    }
  });

  $effect(() => {
    if (filter && _configCache[filter]) {
      notifyEmail = _configCache[filter];
    } else {
      notifyEmail = '';
    }
  });

  async function setFilter(name, fId = '') {
    filter       = name;
    filterFormId = fId;
    page         = 1;
    selected     = null;
    selectedIds  = new Set();
    editingNotify = false;
    await loadSubs();
  }

  async function setStatusFilter(s) {
    statusFilter = s;
    page = 1;
    selected = null;
    selectedIds = new Set();
    await loadSubs();
  }

  async function loadSubs() {
    loadingSubs = true;
    try {
      const params = { page, status: statusFilter };
      if (filter) params.form = filter;
      if (filterFormId) params.form_id = filterFormId;
      const data  = await formsApi.submissions(params);
      submissions = data.submissions || [];
      totalPages  = Math.ceil((data.total || 0) / (data.per_page || 25));
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      loadingSubs = false;
    }
  }

  async function selectSub(sub) {
    selected = sub;
    editingNotes = false;
    notesDraft = sub.notes || '';
    mobileView = 'detail';
    if (!sub.read_at) {
      try {
        await formsApi.markRead(sub.id);
        sub.read_at = new Date().toISOString();
        submissions = [...submissions];
      } catch (e) {}
    }
  }

  function backToList() {
    mobileView = 'list';
    selected = null;
  }

  async function toggleStar(sub) {
    try {
      const res = await formsApi.star(sub.id);
      sub.starred = res.starred;
      submissions = [...submissions];
    } catch (err) {
      addToast(err.message, 'error');
    }
  }

  async function archiveSub(sub) {
    try {
      await formsApi.setStatus(sub.id, 'archived');
      submissions = submissions.filter(s => s.id !== sub.id);
      if (selected?.id === sub.id) selected = null;
      addToast('Submission archived');
    } catch (err) {
      addToast(err.message, 'error');
    }
  }

  async function deleteSub(sub) {
    try {
      await formsApi.delete(sub.id);
      submissions = submissions.filter(s => s.id !== sub.id);
      if (selected?.id === sub.id) selected = null;
      addToast('Submission deleted');
    } catch (err) {
      addToast(err.message, 'error');
    }
  }

  async function saveNotes() {
    if (!selected) return;
    try {
      await formsApi.setNotes(selected.id, notesDraft);
      selected.notes = notesDraft;
      editingNotes = false;
      addToast('Notes saved');
    } catch (err) {
      addToast(err.message, 'error');
    }
  }

  // Bulk actions
  function toggleSelect(id) {
    const next = new Set(selectedIds);
    if (next.has(id)) next.delete(id);
    else next.add(id);
    selectedIds = next;
  }

  function toggleSelectAll() {
    if (selectedIds.size === submissions.length) {
      selectedIds = new Set();
    } else {
      selectedIds = new Set(submissions.map(s => s.id));
    }
  }

  async function bulkAction(action) {
    if (selectedIds.size === 0) return;
    try {
      await formsApi.bulk(Array.from(selectedIds), action);
      selectedIds = new Set();
      addToast(`Bulk ${action} complete`);
      await loadSubs();
    } catch (err) {
      addToast(err.message, 'error');
    }
  }

  async function startNotifyEdit() {
    editingNotify = true;
    notifyDraft = notifyEmail;
  }

  async function saveNotify() {
    if (!filter) return;
    savingNotify = true;
    try {
      await formsApi.setConfig(filter, notifyDraft);
      notifyEmail = notifyDraft;
      _configCache[filter] = notifyDraft;
      editingNotify = false;
      addToast('Notification email saved');
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      savingNotify = false;
    }
  }

  function fmtDate(d) {
    if (!d) return '';
    const dt = new Date(d.includes('T') ? d : d.replace(' ', 'T'));
    if (isNaN(dt)) return '';
    return dt.toLocaleDateString(undefined, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
  }

  function firstValue(data) {
    if (!data || typeof data !== 'object') return '';
    const vals = Object.values(data);
    return (vals[0] ?? '').toString().substring(0, 80);
  }

  function getFormName(sub) {
    if (sub.form_id) {
      const bf = builderForms.find(f => f.id == sub.form_id);
      if (bf) return bf.name;
    }
    return sub.form_name;
  }

  // Get field labels from builder form for this submission
  function getFieldLabels(sub) {
    if (sub.form_id) {
      const bf = builderForms.find(f => f.id == sub.form_id);
      if (bf?.fields) {
        const map = {};
        bf.fields.forEach(f => { map[f.name] = f.label; });
        return map;
      }
    }
    return {};
  }
</script>

<div class="page-container" style="max-width: var(--content-width-wide);">
  <div class="page-header">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="page-header-icon"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
    <div>
      <h1 class="page-title">Submissions</h1>
      <p class="page-subtitle">{totalCount} total submissions</p>
    </div>
    <div class="page-header-actions">
      {#if filter}
        <a class="btn btn-secondary" href={formsApi.exportUrl(filter)} target="_blank">Export CSV</a>
      {/if}
    </div>
  </div>

  {#if loading}
    <div class="loading-overlay"><div class="spinner"></div></div>
  {:else}
    <!-- Mobile filter bar (visible only on mobile) -->
    <div class="subs-mobile-filters">
      <button class="subs-mobile-pill" class:active={!filter} onclick={() => setFilter('')}>All</button>
      {#each formsList as form}
        <button class="subs-mobile-pill" class:active={filter === form.form_name} onclick={() => setFilter(form.form_name)}>
          {form.form_name}
          {#if form.unread > 0}<span class="subs-mobile-badge">{form.unread}</span>{/if}
        </button>
      {/each}
    </div>

    <div class="subs-layout" class:mobile-show-detail={mobileView === 'detail'}>
      <!-- Sidebar: form filter (desktop only) -->
      <div class="subs-sidebar">
        <button class="subs-filter-item" class:active={!filter} onclick={() => setFilter('')}>
          <span>All Forms</span>
          <span class="subs-filter-count">{totalCount}</span>
        </button>
        {#each formsList as form}
          <button class="subs-filter-item" class:active={filter === form.form_name} onclick={() => setFilter(form.form_name)}>
            <span>{form.form_name}</span>
            <span class="subs-filter-count">{form.total}{form.unread > 0 ? ` (${form.unread})` : ''}</span>
          </button>
        {/each}

        <div class="subs-status-filters">
          <div class="subs-status-label">Status</div>
          {#each ['active', 'archived', 'all'] as s}
            <button class="subs-filter-item small" class:active={statusFilter === s} onclick={() => setStatusFilter(s)}>
              {s.charAt(0).toUpperCase() + s.slice(1)}
            </button>
          {/each}
        </div>

        {#if filter}
          <div class="subs-notify-section">
            <div class="subs-status-label">Notify Email</div>
            {#if editingNotify}
              <input type="email" class="subs-notify-input" bind:value={notifyDraft} placeholder="email@example.com" />
              <div class="subs-notify-actions">
                <button class="btn btn-secondary btn-xs" onclick={() => { editingNotify = false; }}>Cancel</button>
                <button class="btn btn-primary btn-xs" onclick={saveNotify} disabled={savingNotify}>Save</button>
              </div>
            {:else}
              <button class="subs-notify-btn" onclick={startNotifyEdit}>
                {notifyEmail || 'Set notification email...'}
              </button>
            {/if}
          </div>
        {/if}
      </div>

      <!-- Main: submission list + detail -->
      <div class="subs-main">
        {#if selectedIds.size > 0}
          <div class="subs-bulk-bar">
            <span>{selectedIds.size} selected</span>
            <button class="btn btn-secondary btn-xs" onclick={() => bulkAction('read')}>Mark Read</button>
            <button class="btn btn-secondary btn-xs" onclick={() => bulkAction('star')}>Star</button>
            <button class="btn btn-secondary btn-xs" onclick={() => bulkAction('archive')}>Archive</button>
            <button class="btn btn-secondary btn-xs btn-danger-text" onclick={() => bulkAction('delete')}>Delete</button>
            <button class="btn btn-secondary btn-xs" onclick={() => { selectedIds = new Set(); }}>Cancel</button>
          </div>
        {/if}

        <div class="subs-list-detail">
          <div class="subs-list">
            {#if loadingSubs}
              <div class="subs-list-loading"><div class="spinner"></div></div>
            {:else if submissions.length === 0}
              <div class="subs-empty">No submissions</div>
            {:else}
              {#each submissions as sub}
                <div
                  class="subs-item"
                  class:selected={selected?.id === sub.id}
                  class:unread={!sub.read_at}
                  onclick={() => selectSub(sub)}
                >
                  <input
                    type="checkbox"
                    class="subs-item-check"
                    checked={selectedIds.has(sub.id)}
                    onclick={(e) => { e.stopPropagation(); toggleSelect(sub.id); }}
                  />
                  <button class="subs-star" class:starred={sub.starred} onclick={(e) => { e.stopPropagation(); toggleStar(sub); }}>
                    {sub.starred ? '★' : '☆'}
                  </button>
                  <div class="subs-item-content">
                    <div class="subs-item-top">
                      <span class="subs-item-form">{getFormName(sub)}</span>
                      <span class="subs-item-date">{fmtDate(sub.created_at)}</span>
                    </div>
                    <div class="subs-item-preview">{firstValue(sub.data)}</div>
                  </div>
                </div>
              {/each}

              {#if totalPages > 1}
                <div class="subs-pagination">
                  <button class="btn btn-secondary btn-xs" disabled={page <= 1} onclick={() => { page--; loadSubs(); }}>Prev</button>
                  <span class="subs-page-info">Page {page} of {totalPages}</span>
                  <button class="btn btn-secondary btn-xs" disabled={page >= totalPages} onclick={() => { page++; loadSubs(); }}>Next</button>
                </div>
              {/if}
            {/if}
          </div>

          <!-- Detail panel -->
          <div class="subs-detail">
            {#if !selected}
              <div class="subs-detail-empty">Select a submission to view details</div>
            {:else}
              {@const labels = getFieldLabels(selected)}
              <div class="subs-detail-header">
                <button class="subs-back-btn" onclick={backToList}>&larr;</button>
                <h3>{getFormName(selected)}</h3>
                <span class="subs-detail-date">{fmtDate(selected.created_at)}</span>
              </div>

              <div class="subs-detail-fields">
                {#each Object.entries(selected.data) as [key, value]}
                  <div class="subs-detail-field">
                    <div class="subs-detail-label">{labels[key] || key}</div>
                    <div class="subs-detail-value">{value || '—'}</div>
                  </div>
                {/each}
              </div>

              <div class="subs-detail-meta">
                <div class="subs-detail-meta-row"><span>IP</span><span>{selected.ip}</span></div>
                {#if selected.user_agent}
                  <div class="subs-detail-meta-row"><span>Browser</span><span class="meta-ua">{selected.user_agent}</span></div>
                {/if}
              </div>

              <div class="subs-detail-actions">
                <button class="btn btn-secondary btn-xs" onclick={() => toggleStar(selected)}>
                  {selected.starred ? 'Unstar' : 'Star'}
                </button>
                <button class="btn btn-secondary btn-xs" onclick={() => archiveSub(selected)}>Archive</button>
                <button class="btn btn-secondary btn-xs btn-danger-text" onclick={() => deleteSub(selected)}>Delete</button>
              </div>

              <!-- Notes -->
              <div class="subs-detail-notes">
                <div class="subs-detail-notes-label">Notes</div>
                {#if editingNotes}
                  <textarea class="subs-notes-input" bind:value={notesDraft} rows="3" placeholder="Add a note..."></textarea>
                  <div class="subs-notes-actions">
                    <button class="btn btn-secondary btn-xs" onclick={() => { editingNotes = false; }}>Cancel</button>
                    <button class="btn btn-primary btn-xs" onclick={saveNotes}>Save</button>
                  </div>
                {:else}
                  <button class="subs-notes-btn" onclick={() => { editingNotes = true; notesDraft = selected.notes || ''; }}>
                    {selected.notes || 'Add a note...'}
                  </button>
                {/if}
              </div>
            {/if}
          </div>
        </div>
      </div>
    </div>
  {/if}
</div>

<style>
  .subs-layout {
    display: flex;
    gap: 0;
    border: 1px solid var(--border-color, #e5e7eb);
    border-radius: var(--radius-lg, 8px);
    overflow: hidden;
    min-height: 500px;
    background: var(--card-bg, #fff);
  }

  .subs-sidebar {
    width: 220px;
    border-right: 1px solid var(--border-color, #e5e7eb);
    padding: 8px 0;
    flex-shrink: 0;
    overflow-y: auto;
  }

  .subs-filter-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    width: 100%;
    padding: 8px 16px;
    background: none;
    border: none;
    font-size: 13px;
    color: var(--text-secondary);
    cursor: pointer;
    text-align: left;
    transition: background 0.1s;
  }

  .subs-filter-item:hover {
    background: var(--bg-hover, #f3f4f6);
  }

  .subs-filter-item.active {
    background: var(--accent-bg, #eff6ff);
    color: var(--accent-color, #2563eb);
    font-weight: 500;
  }

  .subs-filter-item.small {
    padding: 5px 16px;
    font-size: 12px;
  }

  .subs-filter-count {
    font-size: 11px;
    color: var(--text-tertiary);
  }

  .subs-status-filters {
    padding: 8px 0;
    margin-top: 8px;
    border-top: 1px solid var(--border-color, #e5e7eb);
  }

  .subs-status-label {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--text-tertiary);
    font-weight: 500;
    padding: 4px 16px;
  }

  .subs-notify-section {
    padding: 8px 16px;
    margin-top: 8px;
    border-top: 1px solid var(--border-color, #e5e7eb);
  }

  .subs-notify-input {
    width: 100%;
    padding: 4px 6px;
    font-size: 12px;
    border: 1px solid var(--border-color, #e5e7eb);
    border-radius: var(--radius-md, 6px);
    margin-top: 4px;
  }

  .subs-notify-actions {
    display: flex;
    gap: 4px;
    margin-top: 4px;
  }

  .subs-notify-btn {
    display: block;
    width: 100%;
    text-align: left;
    background: none;
    border: none;
    font-size: 12px;
    color: var(--text-tertiary);
    cursor: pointer;
    padding: 4px 0;
  }

  .subs-notify-btn:hover {
    color: var(--text-primary);
  }

  .subs-main {
    flex: 1;
    display: flex;
    flex-direction: column;
    overflow: hidden;
  }

  .subs-bulk-bar {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 16px;
    background: var(--accent-bg, #eff6ff);
    border-bottom: 1px solid var(--border-color, #e5e7eb);
    font-size: 13px;
    flex-shrink: 0;
  }

  .subs-list-detail {
    display: flex;
    flex: 1;
    overflow: hidden;
  }

  .subs-list {
    width: 340px;
    border-right: 1px solid var(--border-color, #e5e7eb);
    overflow-y: auto;
    flex-shrink: 0;
  }

  .subs-list-loading {
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 40px;
  }

  .subs-empty {
    padding: 40px;
    text-align: center;
    color: var(--text-tertiary);
    font-size: 14px;
  }

  .subs-item {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    padding: 10px 12px;
    border-bottom: 1px solid var(--border-color-light, #f3f4f6);
    cursor: pointer;
    transition: background 0.1s;
  }

  .subs-item:hover {
    background: var(--bg-hover, #f3f4f6);
  }

  .subs-item.selected {
    background: var(--accent-bg, #eff6ff);
  }

  .subs-item.unread {
    font-weight: 500;
  }

  .subs-item-check {
    flex-shrink: 0;
    margin-top: 3px;
  }

  .subs-star {
    flex-shrink: 0;
    background: none;
    border: none;
    cursor: pointer;
    font-size: 14px;
    color: var(--text-tertiary);
    padding: 0;
    line-height: 1;
    margin-top: 1px;
  }

  .subs-star.starred {
    color: var(--warning-color, #d97706);
  }

  .subs-item-content {
    flex: 1;
    min-width: 0;
  }

  .subs-item-top {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 8px;
    margin-bottom: 2px;
  }

  .subs-item-form {
    font-size: 13px;
    color: var(--text-primary);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .subs-item-date {
    font-size: 11px;
    color: var(--text-tertiary);
    flex-shrink: 0;
  }

  .subs-item-preview {
    font-size: 12px;
    color: var(--text-tertiary);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .subs-pagination {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 12px;
  }

  .subs-page-info {
    font-size: 12px;
    color: var(--text-tertiary);
  }

  .subs-detail {
    flex: 1;
    overflow-y: auto;
    padding: 20px;
  }

  .subs-detail-empty {
    display: flex;
    align-items: center;
    justify-content: center;
    height: 100%;
    color: var(--text-tertiary);
    font-size: 14px;
  }

  .subs-detail-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 20px;
  }

  .subs-detail-header h3 {
    font-size: 16px;
    font-weight: 600;
    margin: 0;
  }

  .subs-detail-date {
    font-size: 12px;
    color: var(--text-tertiary);
  }

  .subs-detail-fields {
    margin-bottom: 20px;
  }

  .subs-detail-field {
    padding: 10px 0;
    border-bottom: 1px solid var(--border-color-light, #f3f4f6);
  }

  .subs-detail-label {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--text-tertiary);
    font-weight: 500;
    margin-bottom: 2px;
  }

  .subs-detail-value {
    font-size: 14px;
    color: var(--text-primary);
    white-space: pre-wrap;
    word-break: break-word;
  }

  .subs-detail-meta {
    margin: 16px 0;
    padding: 12px 0;
    border-top: 1px solid var(--border-color, #e5e7eb);
  }

  .subs-detail-meta-row {
    display: flex;
    justify-content: space-between;
    font-size: 12px;
    color: var(--text-tertiary);
    padding: 3px 0;
  }

  .meta-ua {
    max-width: 300px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .subs-detail-actions {
    display: flex;
    gap: 6px;
    margin-bottom: 16px;
  }

  .subs-detail-notes {
    border-top: 1px solid var(--border-color, #e5e7eb);
    padding-top: 12px;
  }

  .subs-detail-notes-label {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--text-tertiary);
    font-weight: 500;
    margin-bottom: 6px;
  }

  .subs-notes-input {
    width: 100%;
    padding: 6px 8px;
    font-size: 13px;
    border: 1px solid var(--border-color, #e5e7eb);
    border-radius: var(--radius-md, 6px);
    resize: vertical;
  }

  .subs-notes-actions {
    display: flex;
    gap: 4px;
    margin-top: 6px;
  }

  .subs-notes-btn {
    display: block;
    width: 100%;
    text-align: left;
    background: none;
    border: none;
    font-size: 13px;
    color: var(--text-tertiary);
    cursor: pointer;
    padding: 4px 0;
    white-space: pre-wrap;
    word-break: break-word;
  }

  .subs-notes-btn:hover {
    color: var(--text-primary);
  }

  .btn-xs {
    padding: 3px 8px;
    font-size: 11px;
  }

  .btn-danger-text {
    color: var(--danger-color, #dc2626);
  }

  /* Back button — hidden on desktop */
  .subs-back-btn {
    display: none;
  }

  /* Mobile filter bar — hidden on desktop */
  .subs-mobile-filters {
    display: none;
  }

  /* ── Mobile ──────────────────────────────────────────── */
  @media (max-width: 768px) {
    /* Mobile filter bar */
    .subs-mobile-filters {
      display: flex;
      gap: 6px;
      padding: 8px 0;
      overflow-x: auto;
      -webkit-overflow-scrolling: touch;
      scrollbar-width: none;
    }
    .subs-mobile-filters::-webkit-scrollbar { display: none; }

    .subs-mobile-pill {
      flex-shrink: 0;
      padding: 6px 14px;
      font-size: 13px;
      border: 1px solid var(--border-color, #e5e7eb);
      border-radius: 20px;
      background: var(--card-bg, #fff);
      color: var(--text-secondary);
      cursor: pointer;
      white-space: nowrap;
    }
    .subs-mobile-pill.active {
      background: var(--accent-bg, #eff6ff);
      border-color: var(--accent-color, #2563eb);
      color: var(--accent-color, #2563eb);
      font-weight: 500;
    }
    .subs-mobile-badge {
      display: inline-block;
      min-width: 16px;
      height: 16px;
      padding: 0 4px;
      margin-left: 4px;
      background: var(--accent-color, #2563eb);
      color: #fff;
      font-size: 10px;
      font-weight: 600;
      line-height: 16px;
      text-align: center;
      border-radius: 8px;
    }

    /* Hide desktop sidebar */
    .subs-sidebar {
      display: none;
    }

    /* Layout: single-column, fill available height */
    .subs-layout {
      flex-direction: column;
      min-height: 400px;
      border: none;
      border-radius: 0;
    }

    .subs-main {
      min-height: 0;
    }

    .subs-list-detail {
      flex-direction: column;
    }

    /* List: full width */
    .subs-list {
      width: 100%;
      border-right: none;
      border-bottom: 1px solid var(--border-color, #e5e7eb);
    }

    /* Touch-friendly list items */
    .subs-item {
      padding: 12px 16px;
      gap: 10px;
    }
    .subs-item-check {
      width: 18px;
      height: 18px;
    }
    .subs-star {
      font-size: 18px;
      padding: 4px;
      margin-top: 0;
    }

    /* Detail: full width */
    .subs-detail {
      padding: 16px;
    }

    /* Default: show list, hide detail */
    .subs-layout .subs-detail {
      display: none;
    }
    .subs-layout .subs-list {
      display: block;
    }

    /* When detail is active: show detail, hide list */
    .subs-layout.mobile-show-detail .subs-detail {
      display: block;
    }
    .subs-layout.mobile-show-detail .subs-list {
      display: none;
    }
    .subs-layout.mobile-show-detail .subs-bulk-bar {
      display: none;
    }

    /* Back button */
    .subs-back-btn {
      display: flex;
      align-items: center;
      justify-content: center;
      background: none;
      border: none;
      font-size: 18px;
      color: var(--text-secondary);
      cursor: pointer;
      padding: 4px 8px 4px 0;
      flex-shrink: 0;
    }

    /* Detail header wraps better */
    .subs-detail-header {
      flex-wrap: wrap;
      gap: 4px;
    }
    .subs-detail-header h3 {
      flex: 1;
      min-width: 0;
    }

    /* Meta user agent: don't clip on mobile */
    .meta-ua {
      max-width: none;
      white-space: normal;
      word-break: break-all;
    }

    /* Detail actions: wrap if needed */
    .subs-detail-actions {
      flex-wrap: wrap;
    }

    /* Bulk bar: scroll horizontally */
    .subs-bulk-bar {
      overflow-x: auto;
      -webkit-overflow-scrolling: touch;
      padding: 8px 12px;
      font-size: 12px;
    }

    /* Pagination: comfortable tap targets */
    .subs-pagination {
      padding: 16px 12px;
    }
  }
</style>
