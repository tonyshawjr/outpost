<script>
  import { onMount } from 'svelte';
  import { cache as cacheApi, sync as syncApi, cron as cronApi, analytics as analyticsApi } from '$lib/api.js';
  import { addToast } from '$lib/stores.js';
  import Checkbox from '$components/Checkbox.svelte';

  let { settings = {}, onSettingChange = () => {} } = $props();

  // Geo Analytics
  let geoStatus = $state({ file_exists: false, file_size: 0, file_modified: null, enabled: false });
  let geoLoading = $state(true);
  let geoUploading = $state(false);
  let geoDeleting = $state(false);
  let confirmGeoDelete = $state(false);

  // Sync & Deploy (Outpost-only — Outpost Builder integration)
  let syncKey = $state({ key_set: false, key_masked: '', last_pull: null, last_push: null });
  let syncLoading = $state(true);
  let regenerating = $state(false);
  let showNewKey = $state('');
  let confirmRegenerate = $state(false);

  // Scheduled Publishing
  let cronKey = $state({ key_masked: '', key: '' });
  let cronLoading = $state(true);
  let cronUrl = $derived(
    cronKey.key
      ? `${window.location.origin}${window.location.pathname.replace(/\/outpost\/.*/, '')}/outpost/api.php?action=cron&key=${cronKey.key}`
      : ''
  );

  onMount(async () => {
    try { syncKey = await syncApi.key(); } catch (_) {}
    syncLoading = false;

    try { cronKey = await cronApi.key(); } catch (_) {}
    cronLoading = false;

    try { geoStatus = await analyticsApi.geoStatus(); } catch (_) {}
    geoLoading = false;
  });

  async function regenerateKey() {
    if (!confirmRegenerate) { confirmRegenerate = true; return; }
    confirmRegenerate = false;
    regenerating = true;
    try {
      const data = await syncApi.regenerateKey();
      showNewKey = data.key;
      syncKey = { ...syncKey, key_masked: data.key_masked };
      addToast('API key regenerated', 'success');
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      regenerating = false;
    }
  }

  function copyKey() {
    navigator.clipboard.writeText(showNewKey).then(() => addToast('Key copied', 'success'));
  }

  async function clearCache() {
    try {
      await cacheApi.clear();
      addToast('Cache cleared', 'success');
    } catch (err) {
      addToast(err.message, 'error');
    }
  }


  function copyCronUrl() {
    navigator.clipboard.writeText(cronUrl).then(() => addToast('URL copied', 'success'));
  }

  async function handleGeoUpload(e) {
    const file = e.target.files?.[0];
    if (!file) return;
    geoUploading = true;
    try {
      const result = await analyticsApi.geoUpload(file);
      geoStatus = { ...geoStatus, file_exists: true, file_size: result.file_size, file_modified: result.file_modified };
      addToast('GeoLite2 database uploaded', 'success');
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      geoUploading = false;
      e.target.value = '';
    }
  }

  async function handleGeoDelete() {
    if (!confirmGeoDelete) { confirmGeoDelete = true; return; }
    confirmGeoDelete = false;
    geoDeleting = true;
    try {
      await analyticsApi.geoDelete();
      geoStatus = { file_exists: false, file_size: 0, file_modified: null, enabled: false };
      onSettingChange('geo_enabled', '0');
      addToast('GeoLite2 database removed', 'success');
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      geoDeleting = false;
    }
  }

  function formatFileSize(bytes) {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
  }
</script>

<div class="settings-section">
  <h3 class="settings-section-title">Advanced</h3>
  <p class="settings-section-desc">Cache, deployment, and scheduling configuration.</p>

  <!-- Cache -->
  <div class="adv-block">
    <h4 class="adv-block-title">Cache</h4>
    <div class="form-group">
      <label class="form-label">Enable Page Cache</label>
      <Checkbox checked={settings.cache_enabled === '1'} onchange={() => onSettingChange('cache_enabled', settings.cache_enabled === '1' ? '0' : '1')} />
    </div>
    <button class="btn btn-secondary" onclick={clearCache} type="button">Clear All Cache</button>
  </div>

  <!-- Geo Analytics -->
  <div class="adv-block">
    <h4 class="adv-block-title">Geo Analytics</h4>
    <p class="adv-block-desc">
      Enrich pageview analytics with visitor country data using a MaxMind GeoLite2 database. Only 2-letter country codes are stored — no raw IPs.
    </p>

    {#if geoLoading}
      <p style="font-size: var(--font-size-sm); color: var(--dim);">Loading…</p>
    {:else}
      <div class="form-group">
        <label class="form-label">Enable Geo Enrichment</label>
        <div style="display: flex; align-items: center; gap: var(--space-md);">
          <Checkbox checked={settings.geo_enabled === '1'} onchange={() => onSettingChange('geo_enabled', settings.geo_enabled === '1' ? '0' : '1')} />
          {#if !geoStatus.file_exists}
            <span style="font-size: var(--font-size-xs); color: var(--dim);">Upload a GeoLite2 database first</span>
          {/if}
        </div>
      </div>

      <div class="form-group" style="margin-top: var(--space-lg);">
        <label class="form-label" style="font-size: 0.6875rem; text-transform: uppercase; letter-spacing: 0.06em; color: var(--dim);">GeoLite2-Country Database</label>
        {#if geoStatus.file_exists}
          <div style="display: flex; align-items: center; gap: var(--space-md); margin-bottom: var(--space-sm);">
            <div style="display: flex; align-items: center; gap: var(--space-sm);">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--success)" stroke-width="2"><path d="M20 6L9 17l-5-5"/></svg>
              <span style="font-size: var(--font-size-sm); color: var(--text);">GeoLite2-Country.mmdb</span>
            </div>
            <span style="font-size: var(--font-size-xs); color: var(--dim);">{formatFileSize(geoStatus.file_size)}</span>
            {#if geoStatus.file_modified}
              <span style="font-size: var(--font-size-xs); color: var(--dim);">Uploaded {new Date(geoStatus.file_modified).toLocaleDateString()}</span>
            {/if}
          </div>
          <div style="display: flex; gap: var(--space-sm); align-items: center;">
            <label class="btn btn-secondary" style="cursor: pointer;">
              Replace file
              <input type="file" accept=".mmdb" onchange={handleGeoUpload} hidden disabled={geoUploading} />
            </label>
            {#if confirmGeoDelete}
              <span style="font-size: var(--font-size-sm); color: var(--danger);">Remove database?</span>
              <button class="btn btn-danger" onclick={handleGeoDelete} disabled={geoDeleting} type="button">Yes, remove</button>
              <button class="btn btn-ghost" onclick={() => confirmGeoDelete = false} type="button">Cancel</button>
            {:else}
              <button class="btn btn-ghost" style="color: var(--danger);" onclick={handleGeoDelete} type="button">Remove</button>
            {/if}
          </div>
        {:else}
          <label class="btn btn-secondary" style="cursor: pointer; display: inline-flex; align-items: center; gap: var(--space-xs);">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
            {geoUploading ? 'Uploading…' : 'Upload .mmdb file'}
            <input type="file" accept=".mmdb" onchange={handleGeoUpload} hidden disabled={geoUploading} />
          </label>
          <p class="form-hint">Download the free GeoLite2-Country database from <a href="https://dev.maxmind.com/geoip/geolite2-free-geolocation-data" target="_blank" rel="noopener" style="color: var(--purple);">MaxMind</a> (requires free account).</p>
        {/if}
      </div>
    {/if}
  </div>

  <!-- Sync & Deploy (Outpost-only — Outpost Builder integration) -->
  <div class="adv-block">
    <h4 class="adv-block-title">Sync & Deploy</h4>
    <p class="adv-block-desc">
      Connect this site to Outpost Builder — the local dev tool for building themes with Claude Code.
      Paste this API key into Outpost Builder when adding this site.
    </p>

    {#if syncLoading}
      <p style="font-size: var(--font-size-sm); color: var(--dim);">Loading…</p>
    {:else}
      <div class="form-group" style="margin-bottom: var(--space-lg);">
        <label class="form-label" style="font-size: 0.6875rem; text-transform: uppercase; letter-spacing: 0.06em; color: var(--dim);">API Key</label>
        {#if showNewKey}
          <div style="display: flex; align-items: center; gap: var(--space-sm);">
            <code class="key-code">{showNewKey}</code>
            <button class="btn btn-secondary" style="white-space: nowrap; flex-shrink: 0;" onclick={copyKey} type="button">Copy</button>
          </div>
          <p style="font-size: var(--font-size-xs); color: var(--warning); margin-top: var(--space-xs);">Copy this key now — it won't be shown again.</p>
        {:else}
          <code style="display: block; font-family: var(--font-mono); font-size: var(--font-size-sm); background: var(--hover); padding: var(--space-sm) var(--space-md); border-radius: var(--radius-sm); color: var(--sec);">{syncKey.key_masked || '—'}</code>
        {/if}
      </div>

      {#if syncKey.last_pull || syncKey.last_push}
        <div style="font-size: var(--font-size-xs); color: var(--dim); margin-bottom: var(--space-lg); display: flex; gap: var(--space-xl);">
          {#if syncKey.last_pull}<span>Last pull: {new Date(syncKey.last_pull).toLocaleString()}</span>{/if}
          {#if syncKey.last_push}<span>Last push: {new Date(syncKey.last_push).toLocaleString()}</span>{/if}
        </div>
      {/if}

      {#if confirmRegenerate}
        <div style="display: flex; gap: var(--space-sm); align-items: center;">
          <span style="font-size: var(--font-size-sm); color: var(--danger);">Regenerate? Outpost Builder will need the new key.</span>
          <button class="btn btn-danger" onclick={regenerateKey} disabled={regenerating} type="button">{regenerating ? 'Regenerating…' : 'Yes, regenerate'}</button>
          <button class="btn btn-ghost" onclick={() => confirmRegenerate = false} type="button">Cancel</button>
        </div>
      {:else}
        <button class="btn btn-secondary" onclick={regenerateKey} disabled={regenerating} type="button">Regenerate Key</button>
      {/if}
    {/if}
  </div>

  <!-- Scheduled Publishing -->
  <div class="adv-block">
    <h4 class="adv-block-title">Scheduled Publishing</h4>
    <p class="adv-block-desc">
      Scheduled posts publish automatically whenever anyone visits your site (checked once per minute). This is the same approach WordPress uses. If your site may go long periods without any visitors, use a free service like <a href="https://cron-job.org" target="_blank" rel="noopener" style="color: var(--purple);">cron-job.org</a> or UptimeRobot to ping the URL below on a schedule.
    </p>

    {#if cronLoading}
      <p style="font-size: var(--font-size-sm); color: var(--dim);">Loading…</p>
    {:else if cronUrl}
      <div class="form-group">
        <label class="form-label" style="font-size: 0.6875rem; text-transform: uppercase; letter-spacing: 0.06em; color: var(--dim);">Cron URL (optional)</label>
        <div style="display: flex; align-items: center; gap: var(--space-sm);">
          <code style="flex: 1; font-family: var(--font-mono); font-size: var(--font-size-xs); background: var(--hover); padding: var(--space-sm) var(--space-md); border-radius: var(--radius-sm); color: var(--sec); word-break: break-all;">{cronUrl}</code>
          <button class="btn btn-secondary" style="white-space: nowrap; flex-shrink: 0;" onclick={copyCronUrl} type="button">Copy</button>
        </div>
      </div>
    {/if}
  </div>
</div>

<style>
  .adv-block {
    margin-top: var(--space-2xl);
    padding-top: var(--space-2xl);
    border-top: 1px solid var(--border);
  }
  .adv-block:first-of-type {
    margin-top: var(--space-xl);
    padding-top: 0;
    border-top: none;
  }
  .adv-block-title {
    font-size: 15px;
    font-weight: 600;
    color: var(--text);
    margin: 0 0 var(--space-xs);
  }
  .adv-block-desc {
    font-size: var(--font-size-sm);
    color: var(--sec);
    margin: 0 0 var(--space-lg);
  }
  .form-hint {
    font-size: var(--font-size-xs);
    color: var(--dim);
    margin-top: var(--space-xs);
  }
  .form-hint code {
    font-family: var(--font-mono);
    background: var(--hover);
    padding: 1px 4px;
    border-radius: 3px;
  }
  .key-code {
    flex: 1;
    font-family: var(--font-mono);
    font-size: var(--font-size-sm);
    background: var(--hover);
    padding: var(--space-sm) var(--space-md);
    border-radius: var(--radius-sm);
    color: var(--text);
    word-break: break-all;
  }
</style>
