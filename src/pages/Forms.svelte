<script>
  import { onMount } from 'svelte';
  import { forms as formsApi } from '$lib/api.js';
  import { addToast } from '$lib/stores.js';

  let formsList    = $state([]);
  let submissions  = $state([]);
  let selected     = $state(null);
  let filter       = $state('');
  let page         = $state(1);
  let totalPages   = $state(1);
  let totalCount   = $state(0);
  let unreadCount  = $state(0);
  let loading      = $state(true);
  let loadingSubs  = $state(false);

  // Per-form notification email config
  let notifyEmail    = $state('');   // resolved email for current filter
  let editingNotify  = $state(false);
  let notifyDraft    = $state('');
  let savingNotify   = $state(false);

  // Global notify_email from Settings (loaded once)
  let globalNotifyEmail = $state('');

  onMount(async () => {
    loading = true;
    try {
      const [data, cfgData] = await Promise.all([
        formsApi.list(),
        formsApi.getConfig('').catch(() => ({ configs: {} })),
      ]);
      formsList        = data.forms || [];
      totalCount       = formsList.reduce((s, f) => s + (f.total  || 0), 0);
      unreadCount      = formsList.reduce((s, f) => s + (f.unread || 0), 0);
      _configCache     = cfgData.configs || {};
      await loadSubs();
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      loading = false;
    }
  });

  let _configCache = {};

  // Resolve which notify email applies to the current filter
  $effect(() => {
    if (filter && _configCache[filter]) {
      notifyEmail = _configCache[filter];
    } else {
      notifyEmail = '';
    }
  });

  async function setFilter(name) {
    filter   = name;
    page     = 1;
    selected = null;
    editingNotify = false;
    await loadSubs();
  }

  async function loadSubs() {
    loadingSubs = true;
    try {
      const data  = await formsApi.submissions(filter, page);
      submissions = data.submissions || [];
      totalPages  = data.total_pages || 1;
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      loadingSubs = false;
    }
  }

  async function selectSub(sub) {
    selected = sub;
    editingNotify = false;
    if (!sub.read_at) {
      try {
        await formsApi.markRead(sub.id);
        sub.read_at = new Date().toISOString();
        const f = formsList.find(f => f.form_name === sub.form_name);
        if (f && f.unread > 0) f.unread--;
        unreadCount = Math.max(0, unreadCount - 1);
        formsList   = [...formsList];
        submissions = [...submissions];
      } catch (_) {}
    }
  }

  async function deleteSub() {
    if (!selected) return;
    try {
      await formsApi.delete(selected.id);
      submissions = submissions.filter(s => s.id !== selected.id);
      const f = formsList.find(f => f.form_name === selected.form_name);
      if (f) {
        f.total  = Math.max(0, f.total - 1);
        formsList = [...formsList];
      }
      totalCount = Math.max(0, totalCount - 1);
      selected   = null;
      addToast('Deleted', 'success');
    } catch (err) {
      addToast(err.message, 'error');
    }
  }

  function startEditNotify() {
    notifyDraft   = notifyEmail;
    editingNotify = true;
  }

  async function saveNotify() {
    if (!filter) return; // can't set per-form email when viewing "All"
    savingNotify = true;
    try {
      await formsApi.setConfig(filter, notifyDraft.trim());
      _configCache         = { ..._configCache, [filter]: notifyDraft.trim() };
      notifyEmail          = notifyDraft.trim();
      editingNotify        = false;
      addToast('Notification email saved', 'success');
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      savingNotify = false;
    }
  }

  function cancelEditNotify() {
    editingNotify = false;
    notifyDraft   = '';
  }

  // Find a reply-to email inside submission data fields
  function getReplyEmail(data) {
    if (!data) return '';
    for (const [k, v] of Object.entries(data)) {
      if (/^e.?mail$/i.test(k) && v && v.includes('@')) return v;
    }
    return '';
  }

  function getSender(data) {
    if (!data) return 'Submission';
    return data.name || data.Name || data.email || data.Email || Object.values(data)[0] || 'Submission';
  }

  function getSubject(data) {
    if (!data) return '';
    const entry = Object.entries(data).find(([k]) => !/^name$/i.test(k));
    if (!entry) return '';
    const val = String(entry[1]);
    return val.length > 60 ? val.slice(0, 60) + '…' : val;
  }

  function fmtShort(str) {
    if (!str) return '';
    const d       = new Date(str + ' UTC');
    const now     = new Date();
    const diffDays = Math.floor((now - d) / 86400000);
    if (diffDays === 0) return d.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
    if (diffDays < 7)  return d.toLocaleDateString(undefined, { weekday: 'short' });
    return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
  }

  function fmtFull(str) {
    if (!str) return '';
    return new Date(str + ' UTC').toLocaleString(undefined, { dateStyle: 'long', timeStyle: 'short' });
  }
</script>

<div class="fm-shell">

  <!-- Page Header -->
  <div class="fm-page-header">
    <div class="page-header">
      <div class="page-header-icon clay">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
      </div>
      <div class="page-header-content">
        <h1 class="page-title">Forms</h1>
        <p class="page-subtitle">{totalCount} submission{totalCount === 1 ? '' : 's'}{unreadCount > 0 ? ` · ${unreadCount} unread` : ''}</p>
      </div>
    </div>
  </div>

  <!-- ── Toolbar ──────────────────────────────────────── -->
  <div class="fm-toolbar">
    <div class="fm-toolbar-filters">
      {#if formsList.length > 1}
        <button class="fm-chip" class:active={filter === ''} onclick={() => setFilter('')}>
          All <span class="fm-chip-count">{totalCount}</span>
        </button>
        {#each formsList as form}
          <button class="fm-chip" class:active={filter === form.form_name} onclick={() => setFilter(form.form_name)}>
            {form.form_name}
            <span class="fm-chip-count">{form.total}</span>
            {#if form.unread > 0}<span class="fm-chip-new">{form.unread} new</span>{/if}
          </button>
        {/each}
      {:else if formsList.length === 1}
        <span class="fm-single-label">{formsList[0].form_name}</span>
      {/if}
    </div>

    <!-- Export button -->
    <a
      class="fm-export-btn"
      href={formsApi.exportUrl(filter)}
      download
      title="Download CSV"
    >
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
        <polyline points="7 10 12 15 17 10"/>
        <line x1="12" y1="15" x2="12" y2="3"/>
      </svg>
      Export
    </a>
  </div>

  <!-- ── Notification bar ─────────────────────────────── -->
  <div class="fm-notify-bar">
    {#if filter && !editingNotify}
      <!-- Per-form email set -->
      {#if notifyEmail}
        <svg class="fm-notify-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
          <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
          <polyline points="22,6 12,13 2,6"/>
        </svg>
        <span class="fm-notify-label">Notifications for <strong>{filter}</strong> → <em>{notifyEmail}</em></span>
        <button class="fm-notify-edit" onclick={startEditNotify}>Edit</button>
      {:else}
        <!-- No per-form email — warn -->
        <svg class="fm-notify-icon warn" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
          <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
        </svg>
        <span class="fm-notify-label warn">No notification email set for <strong>{filter}</strong>.</span>
        <button class="fm-notify-edit" onclick={startEditNotify}>Set one</button>
      {/if}

    {:else if filter && editingNotify}
      <!-- Inline editor -->
      <svg class="fm-notify-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
        <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
        <polyline points="22,6 12,13 2,6"/>
      </svg>
      <span class="fm-notify-label">Notify email for <strong>{filter}</strong>:</span>
      <input
        class="fm-notify-input"
        type="email"
        placeholder="you@example.com"
        bind:value={notifyDraft}
        onkeydown={(e) => { if (e.key === 'Enter') saveNotify(); if (e.key === 'Escape') cancelEditNotify(); }}
      />
      <button class="fm-notify-save" onclick={saveNotify} disabled={savingNotify}>
        {savingNotify ? 'Saving…' : 'Save'}
      </button>
      <button class="fm-notify-cancel" onclick={cancelEditNotify}>Cancel</button>

    {:else}
      <!-- "All" view — show a hint -->
      <svg class="fm-notify-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
        <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
        <polyline points="22,6 12,13 2,6"/>
      </svg>
      <span class="fm-notify-label">Select a form above to configure its notification email.</span>
    {/if}
  </div>

  <!-- ── Body ─────────────────────────────────────────── -->
  <div class="fm-body">

    <!-- Left: list -->
    <div class="fm-list">
      {#if loading || loadingSubs}
        <div class="fm-list-loading">Loading…</div>
      {:else if submissions.length === 0}
        <div class="fm-list-empty">No submissions yet.</div>
      {:else}
        {#each submissions as sub}
          {@const isUnread   = !sub.read_at}
          {@const isSelected = selected?.id === sub.id}
          <button
            class="fm-item"
            class:fm-item-unread={isUnread}
            class:fm-item-selected={isSelected}
            onclick={() => selectSub(sub)}
          >
            <div class="fm-item-accent" class:visible={isUnread}></div>
            <div class="fm-item-body">
              <div class="fm-item-top">
                <span class="fm-item-sender">{getSender(sub.data)}</span>
                <span class="fm-item-date">{fmtShort(sub.created_at)}</span>
              </div>
              <div class="fm-item-preview">{getSubject(sub.data)}</div>
              {#if formsList.length > 1 || filter === ''}
                <div class="fm-item-tag">{sub.form_name}</div>
              {/if}
            </div>
          </button>
        {/each}

        {#if totalPages > 1}
          <div class="fm-list-pager">
            <button disabled={page <= 1} onclick={() => { page--; loadSubs(); }}>←</button>
            <span>{page} / {totalPages}</span>
            <button disabled={page >= totalPages} onclick={() => { page++; loadSubs(); }}>→</button>
          </div>
        {/if}
      {/if}
    </div>

    <!-- Right: detail -->
    <div class="fm-detail">
      {#if !selected}
        <div class="fm-detail-empty">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1">
            <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
            <polyline points="22,6 12,13 2,6"/>
          </svg>
          <p>Select a message to read</p>
        </div>
      {:else}
        {@const replyEmail = getReplyEmail(selected.data)}
        <div class="fm-detail-inner">

          <!-- Header -->
          <div class="fm-detail-header">
            <div class="fm-detail-header-row">
              <div class="fm-detail-sender">{getSender(selected.data)}</div>
              <!-- Actions -->
              <div class="fm-detail-actions">
                {#if replyEmail}
                  <a
                    class="fm-action-btn fm-reply-btn"
                    href="mailto:{replyEmail}?subject=Re: {selected.form_name} form"
                  >
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                      <polyline points="9 17 4 12 9 7"/>
                      <path d="M20 18v-2a4 4 0 0 0-4-4H4"/>
                    </svg>
                    Reply
                  </a>
                {/if}
                <button class="fm-action-btn fm-delete-btn" onclick={deleteSub}>
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                    <polyline points="3 6 5 6 21 6"/>
                    <path d="M19 6l-1 14H6L5 6"/>
                    <path d="M10 11v6"/><path d="M14 11v6"/>
                    <path d="M9 6V4h6v2"/>
                  </svg>
                  Delete
                </button>
              </div>
            </div>
            <div class="fm-detail-meta">
              <span class="fm-detail-form-tag">{selected.form_name}</span>
              <span class="fm-detail-timestamp">{fmtFull(selected.created_at)}</span>
              {#if selected.ip}
                <span class="fm-detail-ip">· IP {selected.ip}</span>
              {/if}
            </div>
          </div>

          <!-- Fields -->
          <div class="fm-detail-fields">
            {#each Object.entries(selected.data || {}) as [field, value]}
              <div class="fm-field">
                <div class="fm-field-label">{field}</div>
                <div class="fm-field-value">{value}</div>
              </div>
            {/each}
          </div>

        </div>
      {/if}
    </div>

  </div>
</div>

<style>
  /* ── Shell ─────────────────────────────────────────── */
  .fm-shell {
    display: flex;
    flex-direction: column;
    margin: calc(-1 * var(--space-xl, 24px));
    height: calc(100vh - var(--topbar-height, 56px));
    overflow: hidden;
    border-top: 1px solid var(--border);
  }

  /* ── Page Header ─────────────────────────────────── */
  .fm-page-header {
    padding: var(--space-xl) var(--space-xl) 0;
    flex-shrink: 0;
  }

  /* ── Toolbar ─────────────────────────────────────── */
  .fm-toolbar {
    display: flex;
    align-items: center;
    gap: var(--space-md);
    padding: 0 var(--space-lg);
    height: 48px;
    border-bottom: 1px solid var(--border);
    flex-shrink: 0;
    background: var(--bg-primary);
  }

  .fm-toolbar-filters {
    display: flex;
    align-items: center;
    gap: var(--space-xs);
    flex: 1;
    overflow: hidden;
  }

  .fm-chip {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 3px 10px;
    border-radius: 20px;
    border: 1px solid var(--border);
    background: none;
    font-size: 12px;
    color: var(--text-secondary);
    cursor: pointer;
    transition: all 0.12s;
    white-space: nowrap;
    flex-shrink: 0;
  }
  .fm-chip:hover { color: var(--text-primary); border-color: var(--text-tertiary); }
  .fm-chip.active {
    background: var(--bg-secondary);
    border-color: var(--text-secondary);
    color: var(--text-primary);
    font-weight: 500;
  }
  .fm-chip-count { font-size: 11px; color: var(--text-tertiary); }
  .fm-chip-new   { font-size: 11px; font-weight: 600; color: var(--accent, #6366f1); }

  .fm-single-label {
    font-size: 12px;
    color: var(--text-tertiary);
    padding: 3px 10px;
    border: 1px solid var(--border);
    border-radius: 20px;
    background: var(--bg-secondary);
  }

  /* Export button */
  .fm-export-btn {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 10px;
    border-radius: var(--radius-sm);
    border: 1px solid var(--border);
    background: none;
    font-size: 12px;
    color: var(--text-secondary);
    cursor: pointer;
    text-decoration: none;
    transition: all 0.12s;
    flex-shrink: 0;
  }
  .fm-export-btn:hover {
    color: var(--text-primary);
    border-color: var(--text-secondary);
    background: var(--bg-secondary);
  }
  .fm-export-btn svg { width: 13px; height: 13px; }

  /* ── Notification bar ────────────────────────────── */
  .fm-notify-bar {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
    padding: 0 var(--space-lg);
    height: 38px;
    border-bottom: 1px solid var(--border);
    flex-shrink: 0;
    background: var(--bg-secondary);
    font-size: 12px;
    color: var(--text-secondary);
  }

  .fm-notify-icon {
    width: 14px;
    height: 14px;
    flex-shrink: 0;
    color: var(--text-tertiary);
  }
  .fm-notify-icon.warn { color: var(--warning); }

  .fm-notify-label { flex: 1; min-width: 0; }
  .fm-notify-label.warn { color: var(--warning); }
  .fm-notify-label strong { font-weight: 600; color: var(--text-primary); }
  .fm-notify-label em { font-style: normal; color: var(--text-primary); }

  .fm-notify-edit {
    background: none;
    border: none;
    cursor: pointer;
    font-size: 12px;
    color: var(--accent, #6366f1);
    padding: 2px 4px;
    border-radius: var(--radius-sm);
    transition: opacity 0.1s;
    flex-shrink: 0;
  }
  .fm-notify-edit:hover { opacity: 0.7; }

  .fm-notify-input {
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    padding: 3px 8px;
    font-size: 12px;
    background: var(--bg-primary);
    color: var(--text-primary);
    width: 240px;
    outline: none;
  }
  .fm-notify-input:focus { border-color: var(--accent, #6366f1); }

  .fm-notify-save {
    background: var(--accent, #6366f1);
    color: #fff;
    border: none;
    border-radius: var(--radius-sm);
    padding: 3px 10px;
    font-size: 12px;
    cursor: pointer;
    flex-shrink: 0;
  }
  .fm-notify-save:disabled { opacity: 0.5; cursor: default; }

  .fm-notify-cancel {
    background: none;
    border: none;
    font-size: 12px;
    color: var(--text-tertiary);
    cursor: pointer;
    padding: 3px 6px;
    flex-shrink: 0;
  }
  .fm-notify-cancel:hover { color: var(--text-secondary); }

  /* ── Body ──────────────────────────────────────────── */
  .fm-body {
    display: flex;
    flex: 1;
    overflow: hidden;
  }

  /* ── Left list ─────────────────────────────────────── */
  .fm-list {
    width: 280px;
    flex-shrink: 0;
    border-right: 1px solid var(--border);
    overflow-y: auto;
    background: var(--bg-primary);
  }

  .fm-list-loading,
  .fm-list-empty {
    padding: var(--space-xl) var(--space-lg);
    font-size: var(--font-size-sm);
    color: var(--text-tertiary);
  }

  .fm-item {
    display: flex;
    align-items: stretch;
    width: 100%;
    padding: 0;
    background: none;
    border: none;
    border-bottom: 1px solid var(--border);
    cursor: pointer;
    text-align: left;
    transition: background 0.1s;
  }
  .fm-item:hover          { background: var(--bg-secondary); }
  .fm-item-selected       { background: var(--bg-secondary); }
  .fm-item-selected:hover { background: var(--bg-secondary); }

  .fm-item-accent {
    width: 3px;
    flex-shrink: 0;
    background: transparent;
    transition: background 0.15s;
  }
  .fm-item-accent.visible { background: var(--accent, #6366f1); }

  .fm-item-body {
    flex: 1;
    min-width: 0;
    padding: 11px 12px 11px 10px;
  }

  .fm-item-top {
    display: flex;
    align-items: baseline;
    gap: var(--space-sm);
    margin-bottom: 3px;
  }

  .fm-item-sender {
    flex: 1;
    font-size: var(--font-size-sm);
    color: var(--text-secondary);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    font-weight: 400;
  }
  .fm-item-unread .fm-item-sender { font-weight: 600; color: var(--text-primary); }

  .fm-item-date {
    font-size: 11px;
    color: var(--text-tertiary);
    flex-shrink: 0;
    font-variant-numeric: tabular-nums;
  }
  .fm-item-unread .fm-item-date { color: var(--accent, #6366f1); font-weight: 500; }

  .fm-item-preview {
    font-size: 12px;
    color: var(--text-tertiary);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    margin-bottom: 3px;
  }
  .fm-item-unread .fm-item-preview { color: var(--text-secondary); }

  .fm-item-tag {
    font-size: 11px;
    color: var(--text-tertiary);
    background: var(--bg-tertiary);
    border: 1px solid var(--border);
    padding: 0 6px;
    border-radius: 9px;
    display: inline-block;
    line-height: 1.6;
  }

  .fm-list-pager {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: var(--space-md);
    padding: var(--space-md);
    border-top: 1px solid var(--border);
    font-size: 12px;
    color: var(--text-tertiary);
  }
  .fm-list-pager button {
    background: none;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    padding: 2px 8px;
    cursor: pointer;
    color: var(--text-secondary);
    font-size: 12px;
  }
  .fm-list-pager button:disabled { opacity: 0.35; cursor: default; }

  /* ── Right detail ───────────────────────────────────── */
  .fm-detail {
    flex: 1;
    overflow-y: auto;
    background: var(--bg-primary);
  }

  .fm-detail-empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    height: 100%;
    gap: var(--space-md);
    color: var(--text-tertiary);
  }
  .fm-detail-empty svg { width: 36px; height: 36px; opacity: 0.2; }
  .fm-detail-empty p   { font-size: var(--font-size-sm); }

  .fm-detail-inner {
    display: flex;
    flex-direction: column;
    min-height: 100%;
  }

  /* Detail header */
  .fm-detail-header {
    padding: var(--space-xl) var(--space-xl) var(--space-lg);
    border-bottom: 1px solid var(--border);
    flex-shrink: 0;
  }

  .fm-detail-header-row {
    display: flex;
    align-items: flex-start;
    gap: var(--space-md);
    margin-bottom: var(--space-sm);
  }

  .fm-detail-sender {
    flex: 1;
    font-size: 1.125rem;
    font-weight: 600;
    color: var(--text-primary);
  }

  /* Action buttons in detail header */
  .fm-detail-actions {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
    flex-shrink: 0;
  }

  .fm-action-btn {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 5px 12px;
    border-radius: var(--radius-sm);
    font-size: 13px;
    cursor: pointer;
    text-decoration: none;
    transition: all 0.12s;
    border: 1px solid var(--border);
    background: none;
    color: var(--text-secondary);
  }
  .fm-action-btn svg { width: 13px; height: 13px; }
  .fm-action-btn:hover {
    color: var(--text-primary);
    border-color: var(--text-secondary);
    background: var(--bg-secondary);
  }

  .fm-reply-btn {
    color: var(--accent, #6366f1);
    border-color: var(--accent, #6366f1);
  }
  .fm-reply-btn:hover {
    background: color-mix(in srgb, var(--accent, #6366f1) 10%, transparent);
    color: var(--accent, #6366f1);
    border-color: var(--accent, #6366f1);
  }

  .fm-delete-btn:hover {
    color: var(--danger);
    border-color: var(--danger);
    background: color-mix(in srgb, var(--danger) 10%, transparent);
  }

  .fm-detail-meta {
    display: flex;
    align-items: center;
    gap: var(--space-md);
    flex-wrap: wrap;
  }

  .fm-detail-form-tag {
    font-size: 11px;
    color: var(--text-tertiary);
    background: var(--bg-tertiary);
    border: 1px solid var(--border);
    padding: 1px 8px;
    border-radius: 10px;
    font-weight: 500;
  }

  .fm-detail-timestamp,
  .fm-detail-ip {
    font-size: 12px;
    color: var(--text-tertiary);
  }

  /* Fields */
  .fm-detail-fields {
    padding: var(--space-lg) var(--space-xl);
    display: flex;
    flex-direction: column;
    gap: var(--space-lg);
  }

  .fm-field { display: flex; flex-direction: column; gap: 4px; }

  .fm-field-label {
    font-size: 0.6875rem;
    text-transform: uppercase;
    letter-spacing: 0.07em;
    font-weight: 600;
    color: var(--text-tertiary);
  }

  .fm-field-value {
    font-size: var(--font-size-sm);
    color: var(--text-primary);
    line-height: 1.6;
    white-space: pre-wrap;
    word-break: break-word;
  }

  @media (max-width: 768px) {
    .fm-shell {
      height: calc(100vh - var(--topbar-height, 56px) - var(--mobile-nav-height) - env(safe-area-inset-bottom, 0px));
    }

    .fm-body {
      flex-direction: column;
    }

    .fm-list {
      width: 100%;
      max-height: 40vh;
      border-right: none;
      border-bottom: 1px solid var(--border);
    }

    .fm-toolbar {
      flex-wrap: wrap;
      height: auto;
      padding: var(--space-sm) var(--space-lg);
    }

    .fm-toolbar-filters {
      overflow-x: auto;
      flex: none;
      width: 100%;
    }

    .fm-notify-input {
      width: 100%;
      max-width: 100%;
    }

    .fm-notify-bar {
      flex-wrap: wrap;
      height: auto;
      padding: var(--space-sm) var(--space-lg);
      gap: var(--space-xs);
    }
  }
</style>
