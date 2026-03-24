<script>
  import { onMount } from 'svelte';
  import { collections as collectionsApi, settings as settingsApi } from '$lib/api.js';
  import { addToast, navigate } from '$lib/stores.js';

  let lodgeCollections = $state([]);
  let pendingItems = $state([]);
  let loading = $state(true);
  let loadingPending = $state(true);
  let approvingId = $state(null);
  let rejectingId = $state(null);

  // Lodge configuration
  let lodgeSlug = $state('lodge');
  let lodgeLoginPage = $state('');
  let lodgeRegisterPage = $state('');
  let lodgeForgotPage = $state('');
  let savingConfig = $state(false);
  let sitePages = $state([]);

  onMount(async () => {
    // Load lodge-enabled collections
    try {
      const data = await collectionsApi.list();
      lodgeCollections = (data.collections || []).filter(c => c.lodge_enabled);
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      loading = false;
    }

    // Load pending items
    try {
      const res = await fetch('./api.php?action=lodge/pending', { credentials: 'same-origin' });
      const data = await res.json();
      pendingItems = data.items || [];
    } catch (err) {
      // lodge/pending may not exist yet on older installs
    } finally {
      loadingPending = false;
    }

    // Load lodge settings
    try {
      const data = await settingsApi.get();
      if (data.settings?.lodge_slug) lodgeSlug = data.settings.lodge_slug;
      if (data.settings?.lodge_login_page) lodgeLoginPage = data.settings.lodge_login_page;
      if (data.settings?.lodge_register_page) lodgeRegisterPage = data.settings.lodge_register_page;
      if (data.settings?.lodge_forgot_page) lodgeForgotPage = data.settings.lodge_forgot_page;
    } catch (e) {}

    // Load theme pages for auth page dropdowns
    try {
      const res = await fetch('./api.php?action=lodge/theme-pages', { credentials: 'same-origin' });
      const data = await res.json();
      sitePages = data.pages || [];
    } catch (e) {}
  });

  async function saveLodgeConfig() {
    const slug = lodgeSlug.toLowerCase().replace(/[^a-z0-9-]/g, '').replace(/^-|-$/g, '') || 'lodge';
    lodgeSlug = slug;
    savingConfig = true;
    try {
      await settingsApi.update({
        lodge_slug: slug,
        lodge_login_page: lodgeLoginPage,
        lodge_register_page: lodgeRegisterPage,
        lodge_forgot_page: lodgeForgotPage,
      });
      addToast('Lodge configuration saved', 'success');
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      savingConfig = false;
    }
  }

  async function approveItem(id) {
    approvingId = id;
    try {
      const csrf = document.cookie.match(/outpost_csrf=([^;]+)/)?.[1] || '';
      const res = await fetch('./api.php?action=items/approve', {
        method: 'PUT',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
        body: JSON.stringify({ ids: [id] }),
      });
      const data = await res.json();
      if (data.success || data.updated) {
        pendingItems = pendingItems.filter(i => i.id !== id);
        addToast('Item approved', 'success');
      } else {
        addToast(data.error || 'Failed to approve', 'error');
      }
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      approvingId = null;
    }
  }

  async function rejectItem(id) {
    rejectingId = id;
    try {
      const csrf = document.cookie.match(/outpost_csrf=([^;]+)/)?.[1] || '';
      const res = await fetch('./api.php?action=items/reject', {
        method: 'PUT',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
        body: JSON.stringify({ ids: [id] }),
      });
      const data = await res.json();
      if (data.success || data.updated) {
        pendingItems = pendingItems.filter(i => i.id !== id);
        addToast('Item rejected', 'success');
      } else {
        addToast(data.error || 'Failed to reject', 'error');
      }
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      rejectingId = null;
    }
  }

  function timeAgo(dateStr) {
    const d = new Date(dateStr);
    const now = new Date();
    const diff = Math.floor((now - d) / 1000);
    if (diff < 60) return 'just now';
    if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
    if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
    return Math.floor(diff / 86400) + 'd ago';
  }
</script>

<div class="page-header">
  <div class="page-header-icon sage">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
  </div>
  <div class="page-header-content">
    <h1 class="page-title">Lodge</h1>
    <p class="page-subtitle">Member-owned content portal</p>
  </div>
</div>

{#if loading}
  <div class="loading-overlay"><div class="spinner"></div></div>
{:else}
  <div class="lodge-admin">

    <!-- Pending Review Queue -->
    <section class="lodge-section">
      <h2 class="lodge-section-title">Pending Review</h2>
      <p class="lodge-section-desc">Items submitted by members awaiting your approval.</p>

      {#if loadingPending}
        <p class="lodge-muted">Loading...</p>
      {:else if pendingItems.length === 0}
        <div class="lodge-empty">
          <p>No items pending review.</p>
        </div>
      {:else}
        <div class="lodge-table">
          <div class="lodge-table-header">
            <span class="lodge-th" style="flex:2">Item</span>
            <span class="lodge-th" style="flex:1">Collection</span>
            <span class="lodge-th" style="flex:1">Submitted by</span>
            <span class="lodge-th" style="flex:0 0 80px">When</span>
            <span class="lodge-th" style="flex:0 0 140px; text-align: right;">Actions</span>
          </div>
          {#each pendingItems as item}
            <div class="lodge-table-row">
              <span class="lodge-td" style="flex:2">
                <button class="lodge-item-link" onclick={() => navigate('collection-editor', { collectionSlug: item.collection_slug, itemId: item.id })}>
                  {item.title}
                </button>
              </span>
              <span class="lodge-td" style="flex:1">{item.collection_name}</span>
              <span class="lodge-td" style="flex:1">
                <span class="lodge-member-name">{item.member_name}</span>
                {#if item.member_email}
                  <span class="lodge-member-email">{item.member_email}</span>
                {/if}
              </span>
              <span class="lodge-td" style="flex:0 0 80px; color: var(--text-tertiary);">{timeAgo(item.created_at)}</span>
              <span class="lodge-td" style="flex:0 0 140px; text-align: right; display: flex; gap: 6px; justify-content: flex-end;">
                <button class="btn btn-primary" style="padding: 4px 12px; font-size: 12px;" onclick={() => approveItem(item.id)} disabled={approvingId === item.id}>
                  {approvingId === item.id ? '...' : 'Approve'}
                </button>
                <button class="btn btn-secondary" style="padding: 4px 12px; font-size: 12px;" onclick={() => rejectItem(item.id)} disabled={rejectingId === item.id}>
                  {rejectingId === item.id ? '...' : 'Reject'}
                </button>
              </span>
            </div>
          {/each}
        </div>
      {/if}
    </section>

    <!-- Lodge-Enabled Collections -->
    <section class="lodge-section">
      <h2 class="lodge-section-title">Lodge Collections</h2>
      <p class="lodge-section-desc">Collections with Lodge enabled. Members can create and manage items in these collections.</p>

      {#if lodgeCollections.length === 0}
        <div class="lodge-empty">
          <p>No collections have Lodge enabled yet.</p>
          <button class="btn btn-primary" onclick={() => navigate('collections')}>Manage Collections</button>
        </div>
      {:else}
        <div class="lodge-collections">
          {#each lodgeCollections as col}
            {@const config = (() => { try { return JSON.parse(col.lodge_config || '{}'); } catch { return {}; } })()}
            <div class="lodge-coll-card">
              <div class="lodge-coll-info">
                <h3 class="lodge-coll-name">{col.name}</h3>
                <div class="lodge-coll-meta">
                  <span>/{col.slug}</span>
                  {#if config.require_approval}<span class="lodge-badge">Approval required</span>{/if}
                  {#if config.max_items_per_member > 0}<span>Max {config.max_items_per_member} per member</span>{/if}
                </div>
              </div>
              <div class="lodge-coll-caps">
                {#if config.allow_create !== false}<span class="lodge-cap">Create</span>{/if}
                {#if config.allow_edit !== false}<span class="lodge-cap">Edit</span>{/if}
                {#if config.allow_delete}<span class="lodge-cap">Delete</span>{/if}
              </div>
              <button class="btn btn-secondary" style="padding: 5px 14px; font-size: 13px;" onclick={() => navigate('collections')}>
                Configure
              </button>
            </div>
          {/each}
        </div>
      {/if}
    </section>

    <!-- Lodge Configuration -->
    <section class="lodge-section">
      <h2 class="lodge-section-title">Configuration</h2>
      <p class="lodge-section-desc">Set the Lodge URL slug and assign custom pages for member authentication.</p>

      <div class="lodge-config-grid">
        <div class="lodge-config-field">
          <label class="lodge-config-label">URL Slug</label>
          <div class="lodge-slug-input">
            <span class="lodge-slug-prefix">/</span>
            <input class="input" type="text" bind:value={lodgeSlug} placeholder="lodge" style="padding-left: 24px;" />
          </div>
          <span class="lodge-config-hint">The front-end URL prefix (e.g., /{lodgeSlug}/dashboard)</span>
        </div>
        <div class="lodge-config-field">
          <label class="lodge-config-label">Login Page</label>
          <select class="input" bind:value={lodgeLoginPage}>
            <option value="">Default (Outpost built-in)</option>
            {#each sitePages as page}
              <option value={page.path}>{page.label} ({page.path})</option>
            {/each}
          </select>
        </div>
        <div class="lodge-config-field">
          <label class="lodge-config-label">Register Page</label>
          <select class="input" bind:value={lodgeRegisterPage}>
            <option value="">Default (Outpost built-in)</option>
            {#each sitePages as page}
              <option value={page.path}>{page.label} ({page.path})</option>
            {/each}
          </select>
        </div>
        <div class="lodge-config-field">
          <label class="lodge-config-label">Forgot Password Page</label>
          <select class="input" bind:value={lodgeForgotPage}>
            <option value="">Default (Outpost built-in)</option>
            {#each sitePages as page}
              <option value={page.path}>{page.label} ({page.path})</option>
            {/each}
          </select>
        </div>
      </div>
      <div style="margin-top: var(--space-md);">
        <button class="btn btn-primary" onclick={saveLodgeConfig} disabled={savingConfig}>
          {savingConfig ? 'Saving...' : 'Save Configuration'}
        </button>
      </div>
    </section>
  </div>
{/if}

<style>
  .lodge-admin {
    max-width: var(--content-width-wide);
  }

  .lodge-section {
    margin-bottom: var(--space-3xl);
  }

  .lodge-section-title {
    font-family: var(--font-serif);
    font-size: 18px;
    font-weight: 600;
    color: var(--text-primary);
    margin: 0 0 var(--space-xs);
  }

  .lodge-section-desc {
    font-size: var(--font-size-sm);
    color: var(--text-secondary);
    margin: 0 0 var(--space-lg);
  }

  .lodge-muted {
    font-size: var(--font-size-sm);
    color: var(--text-tertiary);
  }

  .lodge-empty {
    padding: var(--space-2xl) 0;
    text-align: center;
    color: var(--text-tertiary);
    font-size: var(--font-size-sm);
  }

  .lodge-empty p {
    margin: 0 0 var(--space-md);
  }

  /* Pending table */
  .lodge-table {
    border-top: 1px solid var(--border-primary);
  }

  .lodge-table-header {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
    padding: var(--space-sm) 0;
    border-bottom: 1px solid var(--border-primary);
  }

  .lodge-th {
    font-size: 10px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--text-tertiary);
  }

  .lodge-table-row {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
    padding: var(--space-md) 0;
    border-bottom: 1px solid var(--border-primary);
  }

  .lodge-td {
    font-size: 14px;
    color: var(--text-primary);
    min-width: 0;
  }

  .lodge-item-link {
    background: none;
    border: none;
    color: var(--text-primary);
    font-weight: 500;
    font-size: 14px;
    cursor: pointer;
    padding: 0;
    text-align: left;
  }

  .lodge-item-link:hover {
    color: var(--accent);
  }

  .lodge-member-name {
    display: block;
    font-size: 13px;
    font-weight: 500;
  }

  .lodge-member-email {
    display: block;
    font-size: 11px;
    color: var(--text-tertiary);
  }

  /* Collections cards */
  .lodge-collections {
    display: flex;
    flex-direction: column;
    gap: 1px;
    border-top: 1px solid var(--border-primary);
  }

  .lodge-coll-card {
    display: flex;
    align-items: center;
    gap: var(--space-lg);
    padding: var(--space-md) 0;
    border-bottom: 1px solid var(--border-primary);
  }

  .lodge-coll-info {
    flex: 1;
    min-width: 0;
  }

  .lodge-coll-name {
    font-size: 15px;
    font-weight: 500;
    color: var(--text-primary);
    margin: 0 0 2px;
  }

  .lodge-coll-meta {
    display: flex;
    gap: var(--space-md);
    font-size: 12px;
    color: var(--text-tertiary);
  }

  .lodge-badge {
    color: var(--warning);
  }

  .lodge-coll-caps {
    display: flex;
    gap: var(--space-xs);
  }

  .lodge-cap {
    font-size: 11px;
    font-weight: 500;
    color: var(--text-secondary);
    padding: 2px 8px;
    border-radius: var(--radius-sm);
    background: var(--bg-secondary);
  }

  .lodge-config-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: var(--space-md);
  }

  .lodge-config-field {
    display: flex;
    flex-direction: column;
    gap: 4px;
  }

  .lodge-config-label {
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--text-tertiary);
  }

  .lodge-config-hint {
    font-size: 11px;
    color: var(--text-tertiary);
  }

  .lodge-slug-input {
    position: relative;
  }

  .lodge-slug-prefix {
    position: absolute;
    left: 10px;
    top: 50%;
    transform: translateY(-50%);
    font-size: 13px;
    color: var(--text-tertiary);
    font-family: var(--font-mono);
    pointer-events: none;
  }
</style>
