<script>
  import { onMount } from 'svelte';
  import { cache as cacheApi, sync as syncApi, cron as cronApi } from '$lib/api.js';
  import { addToast } from '$lib/stores.js';

  let { settings = {}, onSettingChange = () => {} } = $props();

  // Sync & Deploy
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
  });

  async function clearCache() {
    try {
      await cacheApi.clear();
      addToast('Cache cleared', 'success');
    } catch (err) {
      addToast(err.message, 'error');
    }
  }

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

  function copyCronUrl() {
    navigator.clipboard.writeText(cronUrl).then(() => addToast('URL copied', 'success'));
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
      <button
        class="toggle"
        class:active={settings.cache_enabled === '1'}
        onclick={() => onSettingChange('cache_enabled', settings.cache_enabled === '1' ? '0' : '1')}
        type="button"
      ></button>
    </div>
    <button class="btn btn-secondary" onclick={clearCache} type="button">Clear All Cache</button>
  </div>

  <!-- Sync & Deploy -->
  <div class="adv-block">
    <h4 class="adv-block-title">Sync & Deploy</h4>
    <p class="adv-block-desc">
      Connect this site to Outpost Builder — the local dev tool for building themes with Claude Code.
      Paste this API key into Outpost Builder when adding this site.
    </p>

    {#if syncLoading}
      <p style="font-size: var(--font-size-sm); color: var(--text-tertiary);">Loading…</p>
    {:else}
      <div class="form-group" style="margin-bottom: var(--space-lg);">
        <label class="form-label" style="font-size: 0.6875rem; text-transform: uppercase; letter-spacing: 0.06em; color: var(--text-tertiary);">API Key</label>
        {#if showNewKey}
          <div style="display: flex; align-items: center; gap: var(--space-sm);">
            <code class="key-code">{showNewKey}</code>
            <button class="btn btn-secondary" style="white-space: nowrap; flex-shrink: 0;" onclick={copyKey} type="button">Copy</button>
          </div>
          <p style="font-size: var(--font-size-xs); color: var(--warning); margin-top: var(--space-xs);">Copy this key now — it won't be shown again.</p>
        {:else}
          <code style="display: block; font-family: var(--font-mono); font-size: var(--font-size-sm); background: var(--bg-tertiary); padding: var(--space-sm) var(--space-md); border-radius: var(--radius-sm); color: var(--text-secondary);">{syncKey.key_masked || '—'}</code>
        {/if}
      </div>

      {#if syncKey.last_pull || syncKey.last_push}
        <div style="font-size: var(--font-size-xs); color: var(--text-tertiary); margin-bottom: var(--space-lg); display: flex; gap: var(--space-xl);">
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
      Scheduled posts publish automatically whenever anyone visits your site (checked once per minute). This is the same approach WordPress uses. If your site may go long periods without any visitors, use a free service like <a href="https://cron-job.org" target="_blank" rel="noopener" style="color: var(--accent);">cron-job.org</a> or UptimeRobot to ping the URL below on a schedule.
    </p>

    {#if cronLoading}
      <p style="font-size: var(--font-size-sm); color: var(--text-tertiary);">Loading…</p>
    {:else if cronUrl}
      <div class="form-group">
        <label class="form-label" style="font-size: 0.6875rem; text-transform: uppercase; letter-spacing: 0.06em; color: var(--text-tertiary);">Cron URL (optional)</label>
        <div style="display: flex; align-items: center; gap: var(--space-sm);">
          <code style="flex: 1; font-family: var(--font-mono); font-size: var(--font-size-xs); background: var(--bg-tertiary); padding: var(--space-sm) var(--space-md); border-radius: var(--radius-sm); color: var(--text-secondary); word-break: break-all;">{cronUrl}</code>
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
    border-top: 1px solid var(--border-primary);
  }
  .adv-block:first-of-type {
    margin-top: var(--space-xl);
    padding-top: 0;
    border-top: none;
  }
  .adv-block-title {
    font-size: 15px;
    font-weight: 600;
    color: var(--text-primary);
    margin: 0 0 var(--space-xs);
  }
  .adv-block-desc {
    font-size: var(--font-size-sm);
    color: var(--text-secondary);
    margin: 0 0 var(--space-lg);
  }
  .form-hint {
    font-size: var(--font-size-xs);
    color: var(--text-tertiary);
    margin-top: var(--space-xs);
  }
  .form-hint code {
    font-family: var(--font-mono);
    background: var(--bg-tertiary);
    padding: 1px 4px;
    border-radius: 3px;
  }
  .key-code {
    flex: 1;
    font-family: var(--font-mono);
    font-size: var(--font-size-sm);
    background: var(--bg-tertiary);
    padding: var(--space-sm) var(--space-md);
    border-radius: var(--radius-sm);
    color: var(--text-primary);
    word-break: break-all;
  }
</style>
