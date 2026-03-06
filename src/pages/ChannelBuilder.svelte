<script>
  import { onMount } from 'svelte';
  import { channels } from '$lib/api.js';
  import { currentChannelId, navigate, addToast } from '$lib/stores.js';
  import ConnectStep from '$components/channel-builder/ConnectStep.svelte';
  import SchemaStep from '$components/channel-builder/SchemaStep.svelte';
  import ConfigStep from '$components/channel-builder/ConfigStep.svelte';
  import PreviewStep from '$components/channel-builder/PreviewStep.svelte';

  let channelId = $derived($currentChannelId);
  let channel = $state(null);
  let loading = $state(true);
  let saving = $state(false);
  let syncing = $state(false);

  // Wizard state
  let activeTab = $state('setup'); // setup | data | sync
  let step = $state(1); // 1-4 within setup

  // Channel data
  let config = $state({ url: '', method: 'GET', auth_type: 'none', auth_config: {}, headers: {}, params: {}, data_path: '', id_field: 'id', slug_field: 'slug', pagination: { type: 'none' } });
  let schema = $state([]);
  let sample = $state([]);
  let fieldMap = $state([]);
  let channelName = $state('');
  let channelSlug = $state('');
  let cacheTtl = $state(3600);
  let maxItems = $state(100);
  let sortField = $state('');
  let sortDirection = $state('desc');
  let urlPattern = $state('');

  // Sync tab state
  let syncLog = $state([]);
  let syncLogLoading = $state(false);
  let cachedItems = $state([]);
  let cachedItemsTotal = $state(0);
  let cachedItemsPage = $state(1);

  onMount(async () => {
    if (channelId) {
      await loadChannel();
    } else {
      loading = false;
    }
  });

  async function loadChannel() {
    loading = true;
    try {
      const data = await channels.get(channelId);
      channel = data;
      channelName = data.name || '';
      channelSlug = data.slug || '';
      cacheTtl = data.cache_ttl ?? 3600;
      maxItems = data.max_items ?? 100;
      sortField = data.sort_field || '';
      sortDirection = data.sort_direction || 'desc';
      urlPattern = data.url_pattern || '';

      const cfg = typeof data.config === 'string' ? JSON.parse(data.config) : (data.config || {});
      config = { url: '', method: 'GET', auth_type: 'none', auth_config: {}, headers: {}, params: {}, data_path: '', id_field: 'id', slug_field: 'slug', pagination: { type: 'none' }, ...cfg };

      const fm = typeof data.field_map === 'string' ? JSON.parse(data.field_map) : (data.field_map || []);
      fieldMap = fm;

      // If channel has been synced, default to sync tab
      if (data.last_sync_at) {
        activeTab = 'sync';
      }
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      loading = false;
    }
  }

  async function handleSave() {
    saving = true;
    try {
      const payload = {
        name: channelName,
        config,
        field_map: fieldMap,
        cache_ttl: cacheTtl,
        max_items: maxItems,
        sort_field: sortField,
        sort_direction: sortDirection,
        url_pattern: urlPattern || null,
      };

      if (channelId) {
        await channels.update(channelId, payload);
        addToast('Channel saved');
      } else {
        if (!channelSlug) {
          addToast('Slug is required', 'error');
          saving = false;
          return;
        }
        const res = await channels.create({ ...payload, slug: channelSlug });
        addToast('Channel created');
        navigate('channel-builder', { channelId: res.id });
      }
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      saving = false;
    }
  }

  async function handleSync() {
    if (!channelId) {
      addToast('Save the channel first', 'error');
      return;
    }
    syncing = true;
    try {
      const result = await channels.sync(channelId);
      addToast(`Synced ${result.items_synced} items (${result.items_added} new, ${result.items_updated} updated, ${result.items_removed} removed)`);
      await loadSyncLog();
      await loadCachedItems();
      await loadChannel();
    } catch (err) {
      addToast('Sync failed: ' + err.message, 'error');
    } finally {
      syncing = false;
    }
  }

  async function handleSaveAndSync() {
    await handleSave();
    if (channelId) {
      await handleSync();
    }
  }

  async function loadSyncLog() {
    if (!channelId) return;
    syncLogLoading = true;
    try {
      const data = await channels.syncLog(channelId);
      syncLog = data.logs || [];
    } catch (err) {
      // silent
    } finally {
      syncLogLoading = false;
    }
  }

  async function loadCachedItems(page = 1) {
    if (!channelId) return;
    try {
      const data = await channels.items(channelId, page);
      cachedItems = data.items || [];
      cachedItemsTotal = data.total || 0;
      cachedItemsPage = data.page || 1;
    } catch (err) {
      // silent
    }
  }

  function switchTab(tab) {
    activeTab = tab;
    if (tab === 'sync' && channelId) {
      loadSyncLog();
      loadCachedItems();
    }
    if (tab === 'data' && channelId) {
      loadCachedItems();
    }
  }

  function nextStep() { if (step < 4) step++; }
  function prevStep() { if (step > 1) step--; }

  function timeAgo(dateStr) {
    if (!dateStr) return 'Never';
    const d = new Date(dateStr);
    const diff = (Date.now() - d.getTime()) / 1000;
    if (diff < 60) return 'Just now';
    if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
    if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
    return Math.floor(diff / 86400) + 'd ago';
  }

  function formatDuration(ms) {
    if (!ms) return '—';
    if (ms < 1000) return ms + 'ms';
    return (ms / 1000).toFixed(1) + 's';
  }
</script>

{#if loading}
  <div class="cb-loading"><div class="spinner"></div></div>
{:else}
  <div class="cb-page">
    <!-- Header bar — matches FormBuilder pattern -->
    <div class="cb-header">
      <button class="cb-back" onclick={() => navigate('channels')} title="Back to channels">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 16px; height: 16px;"><polyline points="15 18 9 12 15 6"/></svg>
      </button>
      <input class="cb-name-input" type="text" bind:value={channelName} placeholder="Channel name" />
      {#if channelSlug}
        <span class="cb-slug">channel.{channelSlug}</span>
      {/if}
      <div class="cb-header-actions">
        <button class="btn btn-secondary" onclick={handleSave} disabled={saving}>
          {saving ? 'Saving...' : 'Save'}
        </button>
        {#if channelId}
          <button class="btn btn-primary" onclick={handleSync} disabled={syncing}>
            {syncing ? 'Syncing...' : 'Sync Now'}
          </button>
        {:else}
          <button class="btn btn-primary" onclick={handleSaveAndSync} disabled={saving || syncing}>
            Save & Sync
          </button>
        {/if}
      </div>
    </div>

    <!-- Tabs -->
    <div class="cb-tabs">
      <button class="cb-tab" class:active={activeTab === 'setup'} onclick={() => switchTab('setup')}>Setup</button>
      <button class="cb-tab" class:active={activeTab === 'data'} onclick={() => switchTab('data')}>Data</button>
      {#if channelId}
        <button class="cb-tab" class:active={activeTab === 'sync'} onclick={() => switchTab('sync')}>Sync</button>
      {/if}
    </div>

    <!-- Content -->
    <div class="cb-content">
      <div class="cb-content-inner">

        <!-- Setup Tab -->
        {#if activeTab === 'setup'}
          <div class="cb-step-nav">
            {#each [
              { n: 1, label: 'Connect' },
              { n: 2, label: 'Schema' },
              { n: 3, label: 'Configure' },
              { n: 4, label: 'Preview' },
            ] as s}
              <button
                class="cb-step-link"
                class:active={step === s.n}
                class:done={step > s.n}
                onclick={() => step = s.n}
              >{s.n}. {s.label}</button>
            {/each}
          </div>

          {#if step === 1}
            <ConnectStep bind:config />
            <div class="cb-step-actions">
              <div></div>
              <button class="btn btn-primary" onclick={nextStep}>Next: Schema</button>
            </div>
          {:else if step === 2}
            <SchemaStep bind:config bind:schema bind:sample bind:fieldMap />
            <div class="cb-step-actions">
              <button class="btn btn-secondary" onclick={prevStep}>Back</button>
              <button class="btn btn-primary" onclick={nextStep}>Next: Configure</button>
            </div>
          {:else if step === 3}
            <ConfigStep
              bind:name={channelName}
              bind:slug={channelSlug}
              bind:cacheTtl
              bind:maxItems
              bind:sortField
              bind:sortDirection
              bind:urlPattern
              {schema}
            />
            <div class="cb-step-actions">
              <button class="btn btn-secondary" onclick={prevStep}>Back</button>
              <button class="btn btn-primary" onclick={nextStep}>Next: Preview</button>
            </div>
          {:else if step === 4}
            <PreviewStep {sample} slug={channelSlug} {schema} {urlPattern} {fieldMap} />
            <div class="cb-step-actions">
              <button class="btn btn-secondary" onclick={prevStep}>Back</button>
              <button class="btn btn-primary" onclick={handleSaveAndSync} disabled={saving || syncing}>
                {saving ? 'Saving...' : syncing ? 'Syncing...' : 'Save & Sync'}
              </button>
            </div>
          {/if}

        <!-- Data Tab -->
        {:else if activeTab === 'data'}
          {#if cachedItems.length === 0}
            <div class="cb-empty">
              <p>No items cached yet. Sync the channel to pull data.</p>
              <button class="btn btn-primary" onclick={handleSync} disabled={syncing}>
                {syncing ? 'Syncing...' : 'Sync Now'}
              </button>
            </div>
          {:else}
            <div class="cb-data-header">
              <span class="cb-data-count">{cachedItemsTotal} items cached</span>
            </div>
            <div class="cb-table-wrap">
              <table class="cb-table">
                <thead>
                  <tr>
                    <th>Slug</th>
                    <th>External ID</th>
                    <th>Data (preview)</th>
                    <th>Updated</th>
                  </tr>
                </thead>
                <tbody>
                  {#each cachedItems as item}
                    <tr>
                      <td class="mono">{item.slug || '—'}</td>
                      <td class="mono">{item.external_id || '—'}</td>
                      <td class="cb-data-preview">{JSON.stringify(item.data || {}).slice(0, 120)}</td>
                      <td>{timeAgo(item.updated_at)}</td>
                    </tr>
                  {/each}
                </tbody>
              </table>
            </div>
          {/if}

        <!-- Sync Tab -->
        {:else if activeTab === 'sync'}
          <div class="cb-sync-status">
            <div class="cb-sync-row">
              <span class="cb-sync-label">Status</span>
              <span class="cb-sync-value" class:status-active={channel?.status === 'active'} class:status-error={channel?.status === 'error'}>{channel?.status || 'active'}</span>
            </div>
            <div class="cb-sync-row">
              <span class="cb-sync-label">Items cached</span>
              <span class="cb-sync-value">{channel?.item_count ?? 0}</span>
            </div>
            <div class="cb-sync-row">
              <span class="cb-sync-label">Last sync</span>
              <span class="cb-sync-value">{timeAgo(channel?.last_sync_at)}</span>
            </div>
            <div class="cb-sync-row">
              <span class="cb-sync-label">Cache TTL</span>
              <span class="cb-sync-value">{cacheTtl === 0 ? 'Manual only' : cacheTtl < 3600 ? (cacheTtl / 60) + ' min' : (cacheTtl / 3600) + ' hr'}</span>
            </div>
            {#if channel?.last_error}
              <div class="cb-sync-row">
                <span class="cb-sync-label">Last error</span>
                <span class="cb-sync-value cb-sync-error">{channel.last_error}</span>
              </div>
            {/if}
          </div>

          <div class="cb-sync-actions">
            <button class="btn btn-primary" onclick={handleSync} disabled={syncing}>
              {syncing ? 'Syncing...' : 'Sync Now'}
            </button>
          </div>

          <div class="cb-log-section">
            <span class="cb-section-label">Sync History</span>
            {#if syncLogLoading}
              <div class="cb-loading-inline"><div class="spinner"></div></div>
            {:else if syncLog.length === 0}
              <p class="cb-text-muted">No sync history yet.</p>
            {:else}
              <table class="cb-table">
                <thead>
                  <tr>
                    <th>Time</th>
                    <th>Status</th>
                    <th>Added</th>
                    <th>Updated</th>
                    <th>Removed</th>
                    <th>Duration</th>
                  </tr>
                </thead>
                <tbody>
                  {#each syncLog as log}
                    <tr>
                      <td>{timeAgo(log.synced_at)}</td>
                      <td>
                        <span class="cb-log-status" class:log-success={log.status === 'success'} class:log-running={log.status === 'running'} class:log-error={log.status === 'error'}>{log.status}</span>
                      </td>
                      <td>{log.items_added || 0}</td>
                      <td>{log.items_updated || 0}</td>
                      <td>{log.items_removed || 0}</td>
                      <td>{formatDuration(log.duration_ms)}</td>
                    </tr>
                    {#if log.error_message}
                      <tr>
                        <td colspan="6" class="cb-log-error-msg">{log.error_message}</td>
                      </tr>
                    {/if}
                  {/each}
                </tbody>
              </table>
            {/if}
          </div>
        {/if}

      </div>
    </div>
  </div>
{/if}

<style>
  /* ── Full-height builder layout — matches FormBuilder ── */
  .cb-page {
    display: flex;
    flex-direction: column;
    height: 100%;
    overflow: hidden;
  }

  .cb-loading {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
  }

  /* ── Header bar ── */
  .cb-header {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 20px;
    border-bottom: 1px solid var(--border-primary);
    background: var(--bg-primary);
    flex-shrink: 0;
  }

  .cb-back {
    background: none;
    border: none;
    padding: 4px;
    cursor: pointer;
    color: var(--text-secondary);
    display: flex;
    align-items: center;
    border-radius: var(--radius-sm);
  }

  .cb-back:hover {
    background: var(--bg-hover);
    color: var(--text-primary);
  }

  .cb-name-input {
    flex: 1;
    font-size: 16px;
    font-weight: 600;
    border: 1px solid transparent;
    background: none;
    padding: 4px 8px;
    color: var(--text-primary);
    border-radius: var(--radius-sm);
    font-family: var(--font-sans);
  }

  .cb-name-input:hover {
    border-color: var(--border-primary);
  }

  .cb-name-input:focus {
    border-color: var(--accent);
    outline: none;
  }

  .cb-name-input::placeholder {
    color: var(--text-light);
  }

  .cb-slug {
    font-size: 12px;
    font-family: var(--font-mono);
    color: var(--text-tertiary);
    flex-shrink: 0;
  }

  .cb-header-actions {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-shrink: 0;
  }

  /* ── Tabs ── */
  .cb-tabs {
    display: flex;
    gap: 0;
    border-bottom: 1px solid var(--border-primary);
    background: var(--bg-primary);
    padding: 0 20px;
    flex-shrink: 0;
  }

  .cb-tab {
    padding: 10px 16px;
    font-size: 13px;
    font-weight: 500;
    color: var(--text-secondary);
    background: none;
    border: none;
    border-bottom: 2px solid transparent;
    cursor: pointer;
    transition: color 0.1s, border-color 0.1s;
  }

  .cb-tab:hover {
    color: var(--text-primary);
  }

  .cb-tab.active {
    color: var(--text-primary);
    border-bottom-color: var(--text-primary);
  }

  /* ── Content area ── */
  .cb-content {
    flex: 1;
    overflow-y: auto;
    background: var(--bg-secondary);
  }

  .cb-content-inner {
    max-width: var(--content-width-narrow);
    margin: 0 auto;
    padding: 32px 24px 120px;
  }

  /* ── Step nav — plain text links, no colored pills ── */
  .cb-step-nav {
    display: flex;
    gap: 4px;
    margin-bottom: 24px;
  }

  .cb-step-link {
    background: none;
    border: none;
    padding: 6px 12px;
    font-size: 13px;
    font-weight: 500;
    color: var(--text-tertiary);
    cursor: pointer;
    border-radius: var(--radius-sm);
    transition: color 0.15s, background 0.15s;
  }

  .cb-step-link:hover {
    color: var(--text-primary);
    background: var(--bg-hover);
  }

  .cb-step-link.active {
    color: var(--text-primary);
    font-weight: 600;
    background: var(--bg-primary);
  }

  .cb-step-link.done {
    color: var(--text-secondary);
  }

  /* ── Step actions ── */
  .cb-step-actions {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: 32px;
    padding-top: 24px;
    border-top: 1px solid var(--border-primary);
  }

  /* ── Empty state ── */
  .cb-empty {
    text-align: center;
    padding: 48px 0;
    color: var(--text-secondary);
    font-size: 14px;
  }

  .cb-empty .btn {
    margin-top: 16px;
  }

  /* ── Sync tab ── */
  .cb-sync-status {
    margin-bottom: 24px;
  }

  .cb-sync-row {
    display: flex;
    justify-content: space-between;
    padding: 10px 0;
    border-bottom: 1px solid var(--border-secondary);
  }

  .cb-sync-label {
    font-size: 13px;
    color: var(--text-secondary);
  }

  .cb-sync-value {
    font-size: 13px;
    font-weight: 500;
    color: var(--text-primary);
  }

  .cb-sync-value.status-active {
    color: var(--success);
  }

  .cb-sync-value.status-error {
    color: var(--danger);
  }

  .cb-sync-error {
    color: var(--danger);
    max-width: 300px;
    text-align: right;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .cb-sync-actions {
    margin-bottom: 32px;
  }

  /* ── Section label ── */
  .cb-section-label {
    display: block;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--text-tertiary);
    margin-bottom: 12px;
  }

  .cb-log-section {
    margin-top: 8px;
  }

  .cb-log-status {
    font-size: 12px;
    font-weight: 500;
  }

  .log-success { color: var(--success); }
  .log-running { color: var(--warning); }
  .log-error { color: var(--danger); }

  .cb-log-error-msg {
    font-size: 12px;
    color: var(--danger);
    padding: 4px 12px;
  }

  /* ── Data tab ── */
  .cb-data-header {
    margin-bottom: 12px;
  }

  .cb-data-count {
    font-size: 13px;
    color: var(--text-secondary);
  }

  .cb-table-wrap {
    overflow-x: auto;
  }

  .cb-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
  }

  .cb-table th {
    text-align: left;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--text-tertiary);
    padding: 8px 12px;
    border-bottom: 1px solid var(--border-primary);
  }

  .cb-table td {
    padding: 8px 12px;
    border-bottom: 1px solid var(--border-secondary);
    color: var(--text-primary);
    vertical-align: top;
  }

  .cb-table .mono {
    font-family: var(--font-mono);
    font-size: 12px;
  }

  .cb-data-preview {
    font-family: var(--font-mono);
    font-size: 11px;
    color: var(--text-secondary);
    max-width: 300px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .cb-text-muted {
    color: var(--text-tertiary);
    font-size: 13px;
  }

  .cb-loading-inline {
    display: flex;
    justify-content: center;
    padding: 24px 0;
  }

  /* ── Mobile ── */
  @media (max-width: 768px) {
    .cb-header {
      padding: 10px 12px;
      gap: 8px;
    }

    .cb-slug {
      display: none;
    }

    .cb-tabs {
      padding: 0 12px;
    }

    .cb-content-inner {
      padding: 20px 16px 80px;
    }

    .cb-step-nav {
      flex-wrap: wrap;
    }
  }
</style>
