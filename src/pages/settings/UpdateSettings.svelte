<script>
  import { onMount } from 'svelte';
  import { updates as updatesApi } from '$lib/api.js';
  import { addToast, updateAvailable } from '$lib/stores.js';

  let checking = $state(false);
  let applying = $state(false);
  let updateInfo = $state(null);
  let error = $state('');
  let themeResults = $state(null);

  onMount(() => {
    checkForUpdates();
  });

  async function checkForUpdates() {
    checking = true;
    error = '';
    try {
      updateInfo = await updatesApi.check();
    } catch (err) {
      error = err.message;
    } finally {
      checking = false;
    }
  }

  async function applyUpdate() {
    if (!updateInfo?.download_url) {
      addToast('No download URL available. Create a GitHub Release with a .zip asset first.', 'error');
      return;
    }
    applying = true;
    try {
      const res = await updatesApi.apply(updateInfo.download_url);
      updateAvailable.set(false);
      addToast(res.message || 'Update applied successfully!', 'success');
      themeResults = res.theme_updates || null;
      // Reload after a short delay so the new admin SPA loads (skip if showing results)
      if (!themeResults || themeResults.length === 0) {
        setTimeout(() => window.location.reload(), 1500);
      }
    } catch (err) {
      addToast('Update failed: ' + err.message, 'error');
    } finally {
      applying = false;
    }
  }
</script>

<div class="settings-section">
  <h2 class="settings-section-title">Updates</h2>
  <p class="settings-section-desc">Check for and install Outpost CMS updates.</p>

  {#if checking}
    <div class="update-status">
      <div class="spinner-sm"></div>
      <span class="update-checking">Checking for updates...</span>
    </div>
  {:else if error}
    <div class="update-status">
      <span class="update-error">{error}</span>
      <button class="btn btn-secondary" onclick={checkForUpdates}>Retry</button>
    </div>
  {:else if updateInfo}
    <div class="update-card">
      <div class="update-versions">
        <div class="update-version-row">
          <span class="update-label">Installed</span>
          <span class="update-value">{updateInfo.current_version}</span>
        </div>
        <div class="update-version-row">
          <span class="update-label">Latest</span>
          <span class="update-value" class:update-new={updateInfo.update_available}>{updateInfo.latest_version}</span>
        </div>
      </div>

      {#if updateInfo.update_available}
        <div class="update-available">
          {#if updateInfo.release_notes}
            <details class="update-notes" open>
              <summary>What's new</summary>
              <div class="update-notes-body">{updateInfo.release_notes}</div>
            </details>
          {/if}

          <div class="update-actions">
            {#if updateInfo.download_url}
              <button class="btn btn-primary" onclick={applyUpdate} disabled={applying}>
                {applying ? 'Updating...' : 'Update now'}
              </button>
            {:else}
              <p class="update-no-zip">No downloadable release found. Upload a .zip to the GitHub Release.</p>
            {/if}
            {#if updateInfo.release_url}
              <a href={updateInfo.release_url} target="_blank" rel="noopener" class="update-link">View on GitHub</a>
            {/if}
          </div>

          {#if applying}
            <div class="update-progress">
              <div class="spinner-sm"></div>
              <span>Downloading and applying update... do not close this page.</span>
            </div>
          {/if}
        </div>
      {:else}
        <div class="update-current">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--accent)" stroke-width="2"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
          <span>You're on the latest version.</span>
        </div>
      {/if}
    </div>

    <button class="btn btn-secondary update-recheck" onclick={checkForUpdates} disabled={checking}>
      Check again
    </button>
  {/if}

  {#if themeResults && themeResults.length > 0}
    <div class="theme-results">
      <h3 class="theme-results-title">Theme Updates</h3>
      {#each themeResults as result}
        <div class="theme-result-item">
          <div class="theme-result-header">
            <strong>{result.theme}</strong>
            {#if result.action === 'installed'}
              <span class="theme-result-badge installed">Installed v{result.version}</span>
            {:else if result.action === 'updated'}
              <span class="theme-result-badge updated">Updated {result.from_version} → {result.to_version}</span>
            {:else if result.action === 'skipped'}
              <span class="theme-result-badge skipped">Up to date</span>
            {/if}
          </div>
          {#if result.conflicts && result.conflicts.length > 0}
            <details class="theme-conflicts">
              <summary>{result.conflicts.length} file{result.conflicts.length === 1 ? '' : 's'} skipped (user-modified)</summary>
              <ul class="conflict-list">
                {#each result.conflicts as file}
                  <li>{file}</li>
                {/each}
              </ul>
            </details>
          {/if}
        </div>
      {/each}
      <button class="btn btn-primary" style="margin-top: var(--space-md);" onclick={() => window.location.reload()}>
        Reload admin
      </button>
    </div>
  {/if}
</div>

<style>
  .update-status {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
  }

  .update-checking {
    font-size: 14px;
    color: var(--text-secondary);
  }

  .update-error {
    font-size: 14px;
    color: var(--danger);
  }

  .update-card {
    border: 1px solid var(--border-primary);
    border-radius: var(--radius-md);
    padding: var(--space-lg);
    margin-bottom: var(--space-md);
  }

  .update-versions {
    display: flex;
    gap: var(--space-xl);
    margin-bottom: var(--space-md);
  }

  .update-version-row {
    display: flex;
    flex-direction: column;
    gap: 2px;
  }

  .update-label {
    font-size: 11px;
    font-weight: 600;
    color: var(--text-tertiary);
    text-transform: uppercase;
    letter-spacing: 0.06em;
  }

  .update-value {
    font-size: 16px;
    font-weight: 500;
    color: var(--text-primary);
    font-family: var(--font-mono, monospace);
  }

  .update-value.update-new {
    color: var(--accent);
  }

  .update-available {
    border-top: 1px solid var(--border-secondary);
    padding-top: var(--space-md);
  }

  .update-notes {
    margin-bottom: var(--space-md);
  }

  .update-notes summary {
    font-size: 13px;
    color: var(--text-secondary);
    cursor: pointer;
  }

  .update-notes-body {
    margin-top: var(--space-sm);
    font-size: 13px;
    color: var(--text-secondary);
    line-height: 1.6;
    white-space: pre-wrap;
    max-height: 400px;
    overflow-y: auto;
    padding: var(--space-sm);
    background: var(--bg-tertiary);
    border-radius: var(--radius-sm);
  }

  .update-actions {
    display: flex;
    align-items: center;
    gap: var(--space-md);
  }

  .update-link {
    font-size: 13px;
    color: var(--text-secondary);
    text-decoration: underline;
  }

  .update-no-zip {
    font-size: 13px;
    color: var(--text-tertiary);
    margin: 0;
  }

  .update-progress {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
    margin-top: var(--space-md);
    font-size: 13px;
    color: var(--text-secondary);
  }

  .update-current {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
    font-size: 14px;
    color: var(--text-secondary);
  }

  .update-recheck {
    font-size: 13px;
  }

  .spinner-sm {
    width: 16px;
    height: 16px;
    border: 2px solid var(--border-secondary);
    border-top-color: var(--accent);
    border-radius: 50%;
    animation: spin 0.6s linear infinite;
  }

  @keyframes spin {
    to { transform: rotate(360deg); }
  }

  .theme-results {
    margin-top: var(--space-xl);
    border: 1px solid var(--border-primary);
    border-radius: var(--radius-md);
    padding: var(--space-lg);
  }

  .theme-results-title {
    font-size: 14px;
    font-weight: 600;
    margin: 0 0 var(--space-md);
    color: var(--text-primary);
  }

  .theme-result-item {
    padding: var(--space-sm) 0;
  }

  .theme-result-item + .theme-result-item {
    border-top: 1px solid var(--border-secondary);
  }

  .theme-result-header {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
    font-size: 13px;
  }

  .theme-result-badge {
    font-size: 11px;
    font-weight: 500;
  }

  .theme-result-badge.installed {
    color: var(--accent);
  }

  .theme-result-badge.updated {
    color: var(--accent);
  }

  .theme-result-badge.skipped {
    color: var(--text-tertiary);
  }

  .theme-conflicts {
    margin-top: var(--space-xs);
  }

  .theme-conflicts summary {
    font-size: 12px;
    color: var(--warning, #d97706);
    cursor: pointer;
  }

  .conflict-list {
    list-style: none;
    padding: 0;
    margin: var(--space-xs) 0 0;
  }

  .conflict-list li {
    font-size: 12px;
    color: var(--text-secondary);
    font-family: var(--font-mono, monospace);
    padding: 2px 0;
  }
</style>
