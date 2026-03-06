<script>
  import { onMount } from 'svelte';
  import { channels } from '$lib/api.js';
  import { navigate, addToast } from '$lib/stores.js';
  import { slugify } from '$lib/utils.js';

  let channelList = $state([]);
  let loading = $state(true);
  let creating = $state(false);
  let channelName = $state('');
  let channelSlug = $state('');
  let channelType = $state('api');
  let submitting = $state(false);
  let deleteConfirmId = $state(null);

  onMount(loadChannels);

  async function loadChannels() {
    loading = true;
    try {
      const data = await channels.list();
      channelList = data.channels || [];
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      loading = false;
    }
  }

  function startCreate() {
    creating = true;
    channelName = '';
    channelSlug = '';
    channelType = 'api';
  }

  function cancelCreate() {
    creating = false;
  }

  function autoSlug() {
    channelSlug = slugify(channelName);
  }

  async function handleCreate() {
    if (!channelName.trim() || !channelSlug.trim()) return;
    submitting = true;
    try {
      const res = await channels.create({
        name: channelName.trim(),
        slug: channelSlug.trim(),
        type: channelType,
      });
      addToast('Channel created');
      navigate('channel-builder', { channelId: res.id });
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      submitting = false;
    }
  }

  async function handleDelete(ch) {
    if (deleteConfirmId !== ch.id) {
      deleteConfirmId = ch.id;
      return;
    }
    try {
      await channels.delete(ch.id);
      addToast('Channel deleted');
      deleteConfirmId = null;
      await loadChannels();
    } catch (err) {
      addToast(err.message, 'error');
    }
  }

  function statusClass(s) {
    return { active: 'status-active', paused: 'status-paused', error: 'status-error' }[s] || '';
  }

  function timeAgo(dateStr) {
    if (!dateStr) return 'Never';
    const d = new Date(dateStr);
    const diff = (Date.now() - d.getTime()) / 1000;
    if (diff < 60) return 'Just now';
    if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
    if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
    return Math.floor(diff / 86400) + 'd ago';
  }
</script>

<div class="page-container" style="max-width: var(--content-width-wide);">
  <div class="page-header">
    <div class="page-header-icon green">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M4.9 19.1C1 15.2 1 8.8 4.9 4.9"/><path d="M7.8 16.2c-2.3-2.3-2.3-6.1 0-8.4"/><circle cx="12" cy="12" r="2"/><path d="M16.2 7.8c2.3 2.3 2.3 6.1 0 8.4"/><path d="M19.1 4.9C23 8.8 23 15.2 19.1 19.1"/></svg>
    </div>
    <div class="page-header-content">
      <h1 class="page-title">Channels</h1>
      <p class="page-subtitle">Pull external data into your templates</p>
    </div>
    <div class="page-header-actions">
      {#if !creating}
        <button class="btn btn-primary" onclick={startCreate}>New Channel</button>
      {/if}
    </div>
  </div>

  {#if creating}
    <div class="create-row">
      <div class="create-fields">
        <div class="create-field">
          <label class="create-label">Type</label>
          <div class="create-type-pills">
            {#each [
              { value: 'api', label: 'REST API' },
              { value: 'rss', label: 'RSS Feed' },
              { value: 'csv', label: 'CSV' },
            ] as t}
              <button
                class="type-pill"
                class:type-pill-active={channelType === t.value}
                onclick={() => channelType = t.value}
              >{t.label}</button>
            {/each}
          </div>
        </div>
        <div class="create-field">
          <label class="create-label">Name</label>
          <input
            type="text"
            class="create-input"
            bind:value={channelName}
            oninput={autoSlug}
            placeholder="Product Feed"
            autofocus
            onkeydown={(e) => { if (e.key === 'Enter') handleCreate(); if (e.key === 'Escape') cancelCreate(); }}
          />
        </div>
        <div class="create-field">
          <label class="create-label">Slug</label>
          <input
            type="text"
            class="create-input create-input-mono"
            bind:value={channelSlug}
            placeholder="products"
            onkeydown={(e) => { if (e.key === 'Enter') handleCreate(); if (e.key === 'Escape') cancelCreate(); }}
          />
        </div>
        {#if channelSlug}
          <div class="create-hint">{`{% for item in channel.${channelSlug} %}`}</div>
        {/if}
      </div>
      <div class="create-actions">
        <button class="btn btn-secondary" onclick={cancelCreate}>Cancel</button>
        <button class="btn btn-primary" onclick={handleCreate} disabled={submitting || !channelName.trim() || !channelSlug.trim()}>
          {submitting ? 'Creating...' : 'Create'}
        </button>
      </div>
    </div>
  {/if}

  {#if loading}
    <div class="loading-overlay"><div class="spinner"></div></div>
  {:else if channelList.length === 0 && !creating}
    <div class="empty-state">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1" style="width: 48px; height: 48px; opacity: 0.3; margin-bottom: 16px;"><path d="M4.9 19.1C1 15.2 1 8.8 4.9 4.9"/><path d="M7.8 16.2c-2.3-2.3-2.3-6.1 0-8.4"/><circle cx="12" cy="12" r="2"/><path d="M16.2 7.8c2.3 2.3 2.3 6.1 0 8.4"/><path d="M19.1 4.9C23 8.8 23 15.2 19.1 19.1"/></svg>
      <h3>No channels yet</h3>
      <p>Pull external data into your templates from APIs, RSS feeds, and CSV files.</p>
      <button class="btn btn-primary" onclick={startCreate}>Create Channel</button>
    </div>
  {:else}
    <div class="channels-grid">
      {#each channelList as ch}
        <div class="channel-card">
          <div class="channel-card-header">
            <h3 class="channel-card-name" onclick={() => navigate('channel-builder', { channelId: ch.id })}>{ch.name}</h3>
            <span class="channel-card-status {statusClass(ch.status)}">{ch.status}</span>
          </div>
          <div class="channel-card-meta">
            <span class="channel-card-type">{ch.type === 'rss' ? 'RSS' : ch.type === 'csv' ? 'CSV' : 'API'}</span>
            <span class="channel-card-dot"></span>
            <span class="channel-card-slug">{ch.slug}</span>
            <span class="channel-card-dot"></span>
            <span>{ch.item_count || 0} items</span>
            <span class="channel-card-dot"></span>
            <span>Synced {timeAgo(ch.last_sync_at)}</span>
          </div>
          {#if ch.last_error}
            <div class="channel-card-error">{ch.last_error}</div>
          {/if}
          <div class="channel-card-actions">
            <button class="channel-card-link" onclick={() => navigate('channel-builder', { channelId: ch.id })}>Configure</button>
            <button
              class="channel-card-link channel-card-link-danger"
              onclick={() => handleDelete(ch)}
              onmouseleave={() => { if (deleteConfirmId === ch.id) deleteConfirmId = null; }}
            >
              {deleteConfirmId === ch.id ? 'Confirm delete' : 'Delete'}
            </button>
          </div>
        </div>
      {/each}
    </div>
  {/if}
</div>

<style>
  /* Create row — matches FormsList pattern */
  .create-row {
    display: flex;
    align-items: flex-end;
    gap: 16px;
    padding: 20px 0;
    margin-bottom: 8px;
    border-bottom: 1px solid var(--border-primary);
  }

  .create-fields {
    flex: 1;
    display: flex;
    align-items: flex-end;
    gap: 16px;
    flex-wrap: wrap;
  }

  .create-field {
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 180px;
  }

  .create-label {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--text-tertiary);
    font-weight: 500;
  }

  .create-input {
    padding: 6px 0;
    font-size: 14px;
    border: none;
    border-bottom: 1px solid var(--border-primary);
    background: none;
    color: var(--text-primary);
    outline: none;
    transition: border-color 0.15s;
  }

  .create-input:focus {
    border-bottom-color: var(--accent);
  }

  .create-input-mono {
    font-family: var(--font-mono);
    font-size: 13px;
  }

  .create-hint {
    font-size: 12px;
    color: var(--text-tertiary);
    font-family: var(--font-mono);
    align-self: center;
    padding-bottom: 6px;
  }

  .create-actions {
    display: flex;
    gap: 8px;
    flex-shrink: 0;
  }

  /* Card grid */
  .channels-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 16px;
    margin-top: 8px;
  }

  .channel-card {
    border: 1px solid var(--border-primary);
    border-radius: var(--radius-lg);
    padding: 20px;
    transition: border-color 0.15s;
  }

  .channel-card:hover {
    border-color: var(--border-secondary);
  }

  .channel-card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 6px;
  }

  .channel-card-name {
    font-size: 15px;
    font-weight: 600;
    color: var(--text-primary);
    cursor: pointer;
    margin: 0;
  }

  .channel-card-name:hover {
    color: var(--accent);
  }

  .channel-card-status {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--text-tertiary);
    font-weight: 500;
  }

  .channel-card-status.status-active {
    color: var(--success);
  }

  .channel-card-status.status-error {
    color: var(--danger);
  }

  .channel-card-meta {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 13px;
    color: var(--text-tertiary);
    margin-bottom: 16px;
  }

  .channel-card-slug {
    font-family: var(--font-mono);
    font-size: 12px;
  }

  .channel-card-dot {
    width: 3px;
    height: 3px;
    border-radius: 50%;
    background: var(--text-tertiary);
    opacity: 0.4;
  }

  .channel-card-error {
    font-size: 12px;
    color: var(--danger);
    margin-top: 6px;
    padding: 6px 8px;
    background: var(--danger-soft);
    border-radius: var(--radius-sm);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .channel-card-actions {
    display: flex;
    gap: 12px;
  }

  .channel-card-link {
    background: none;
    border: none;
    font-size: 13px;
    color: var(--text-secondary);
    cursor: pointer;
    padding: 0;
    transition: color 0.1s;
  }

  .channel-card-link:hover {
    color: var(--text-primary);
  }

  .channel-card-link-danger:hover {
    color: var(--danger);
  }

  .channel-card-type {
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--text-secondary);
  }

  .create-type-pills {
    display: flex;
    gap: 4px;
  }

  .type-pill {
    padding: 4px 10px;
    font-size: 12px;
    font-weight: 500;
    color: var(--text-secondary);
    background: transparent;
    border: 1px solid transparent;
    border-radius: var(--radius-sm);
    cursor: pointer;
    transition: all 0.15s;
  }

  .type-pill:hover {
    background: var(--bg-secondary);
  }

  .type-pill-active {
    color: var(--text-primary);
    background: var(--bg-secondary);
    border-color: var(--border-primary);
  }
</style>
