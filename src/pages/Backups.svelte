<script>
  import { onMount } from 'svelte';
  import { backup as backupApi } from '$lib/api.js';
  import { addToast } from '$lib/stores.js';
  import Checkbox from '$components/Checkbox.svelte';

  let backups = $state([]);
  let loading = $state(true);
  let creating = $state(false);
  let restoring = $state(false);
  let restoreFile = $state(null);
  let restoreConfirm = $state(false);
  let deleteConfirm = $state(null);

  // Auto-backup settings
  let autoEnabled = $state(false);
  let frequency = $state('daily');
  let maxBackups = $state(10);
  let settingsLoading = $state(true);
  let savingSettings = $state(false);
  let settingsChanged = $state(false);

  let fileInput;

  onMount(() => {
    loadBackups();
    loadSettings();
  });

  async function loadBackups() {
    loading = true;
    try {
      const data = await backupApi.list();
      backups = data.backups || [];
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      loading = false;
    }
  }

  async function loadSettings() {
    settingsLoading = true;
    try {
      const data = await backupApi.getSettings();
      autoEnabled = data.auto_enabled ?? false;
      frequency = data.frequency ?? 'daily';
      maxBackups = data.max_backups ?? 10;
    } catch {
      // Settings endpoint may not exist yet — use defaults
    } finally {
      settingsLoading = false;
    }
  }

  async function handleCreate() {
    creating = true;
    try {
      await backupApi.create();
      addToast('Backup created successfully', 'success');
      await loadBackups();
    } catch (err) {
      addToast('Failed to create backup: ' + err.message, 'error');
    } finally {
      creating = false;
    }
  }

  function handleDownload(filename) {
    backupApi.download(filename);
  }

  async function handleDelete(filename) {
    if (deleteConfirm !== filename) {
      deleteConfirm = filename;
      return;
    }
    try {
      await backupApi.delete(filename);
      addToast('Backup deleted', 'success');
      deleteConfirm = null;
      await loadBackups();
    } catch (err) {
      addToast('Failed to delete: ' + err.message, 'error');
    }
  }

  function cancelDelete() {
    deleteConfirm = null;
  }

  function handleFileSelect(e) {
    const file = e.target.files?.[0];
    if (file) {
      restoreFile = file;
      restoreConfirm = false;
    }
  }

  function clearFile() {
    restoreFile = null;
    restoreConfirm = false;
    if (fileInput) fileInput.value = '';
  }

  async function handleRestore() {
    if (!restoreConfirm) {
      restoreConfirm = true;
      return;
    }
    restoring = true;
    try {
      await backupApi.restore(restoreFile);
      addToast('Backup restored successfully. Reloading...', 'success');
      setTimeout(() => window.location.reload(), 1500);
    } catch (err) {
      addToast('Failed to restore: ' + err.message, 'error');
    } finally {
      restoring = false;
      restoreConfirm = false;
    }
  }

  function handleSettingChange() {
    settingsChanged = true;
  }

  async function handleSaveSettings() {
    savingSettings = true;
    try {
      await backupApi.updateSettings({
        auto_enabled: autoEnabled,
        frequency,
        max_backups: maxBackups,
      });
      settingsChanged = false;
      addToast('Backup settings saved', 'success');
    } catch (err) {
      addToast('Failed to save settings: ' + err.message, 'error');
    } finally {
      savingSettings = false;
    }
  }

  function formatBytes(bytes) {
    if (bytes === 0) return '0 B';
    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
  }

  function formatDate(dateStr) {
    if (!dateStr) return '';
    const d = new Date(dateStr);
    return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
      + ' ' + d.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
  }
</script>

<div class="bk">
  <div class="page-header">
    <div class="page-header-icon sage">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M21 8v13H3V8"/><path d="M1 3h22v5H1z"/><path d="M10 12h4"/></svg>
    </div>
    <div class="page-header-content">
      <h1 class="page-title">Backups</h1>
      <p class="page-subtitle">Back up your site's database and uploaded media, or restore from a previous backup.</p>
    </div>
    <div class="page-header-actions">
      <button
        class="btn btn-primary"
        onclick={handleCreate}
        disabled={creating}
      >
        {creating ? 'Creating...' : 'Create Backup'}
      </button>
    </div>
  </div>

  <!-- Backup History -->
  <div class="bk-section">
    <div class="bk-label">Backup History</div>

    {#if loading}
      <div class="loading-overlay"><div class="spinner"></div></div>
    {:else if backups.length === 0}
      <div class="bk-empty">
        <p class="bk-empty-text">No backups yet. Create your first backup above.</p>
      </div>
    {:else}
      <div class="bk-table">
        <div class="bk-table-header">
          <span class="bk-col-name">Filename</span>
          <span class="bk-col-size">Size</span>
          <span class="bk-col-date">Date</span>
          <span class="bk-col-actions">Actions</span>
        </div>
        {#each backups as b (b.filename)}
          <div class="bk-table-row">
            <span class="bk-col-name bk-filename">{b.filename}</span>
            <span class="bk-col-size">{formatBytes(b.size)}</span>
            <span class="bk-col-date">{formatDate(b.date)}</span>
            <span class="bk-col-actions">
              <button class="bk-action" onclick={() => handleDownload(b.filename)}>Download</button>
              {#if deleteConfirm === b.filename}
                <button class="bk-action bk-action--danger" onclick={() => handleDelete(b.filename)}>Confirm</button>
                <button class="bk-action" onclick={cancelDelete}>Cancel</button>
              {:else}
                <button class="bk-action bk-action--danger" onclick={() => handleDelete(b.filename)}>Delete</button>
              {/if}
            </span>
          </div>
        {/each}
      </div>
    {/if}
  </div>

  <!-- Restore -->
  <div class="bk-section">
    <div class="bk-label">Restore from Backup</div>
    <p class="bk-desc">Upload a backup zip file to restore your site to a previous state. This will replace the current database and uploaded media.</p>

    <div class="bk-restore-row">
      <input
        bind:this={fileInput}
        type="file"
        accept=".zip"
        onchange={handleFileSelect}
        class="bk-file-input"
      />
      {#if restoreFile}
        <span class="bk-file-name">{restoreFile.name}</span>
        <button class="bk-action" onclick={clearFile}>Clear</button>
      {/if}
    </div>

    {#if restoreFile && restoreConfirm}
      <div class="bk-confirm-banner">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
        <span>Are you sure? This will overwrite your current database and uploads. This cannot be undone.</span>
      </div>
    {/if}

    <div class="bk-restore-actions">
      <button
        class="btn btn-primary"
        onclick={handleRestore}
        disabled={!restoreFile || restoring}
      >
        {#if restoring}
          Restoring...
        {:else if restoreConfirm}
          Confirm Restore
        {:else}
          Restore
        {/if}
      </button>
    </div>

    <div class="bk-warning">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
      <span>Restoring will overwrite your current database and uploads. This cannot be undone. Make sure you have a current backup before restoring.</span>
    </div>
  </div>

  <!-- Automatic Backups -->
  <div class="bk-section">
    <div class="bk-label">Automatic Backups</div>

    {#if settingsLoading}
      <div class="bk-settings-loading">Loading settings...</div>
    {:else}
      <div class="bk-settings">
        <div class="bk-toggle-row">
          <Checkbox bind:checked={autoEnabled} onchange={handleSettingChange} label="Enable automatic backups" />
        </div>

        {#if autoEnabled}
          <div class="bk-settings-fields">
            <div class="bk-field">
              <label class="bk-field-label" for="bk-frequency">Frequency</label>
              <select
                id="bk-frequency"
                class="bk-select"
                bind:value={frequency}
                onchange={handleSettingChange}
              >
                <option value="daily">Daily</option>
                <option value="weekly">Weekly</option>
              </select>
            </div>

            <div class="bk-field">
              <label class="bk-field-label" for="bk-max">Keep last</label>
              <input
                id="bk-max"
                type="number"
                min="1"
                max="100"
                class="bk-number-input"
                bind:value={maxBackups}
                oninput={handleSettingChange}
              />
              <span class="bk-field-hint">backups</span>
            </div>
          </div>
        {/if}

        <div class="bk-settings-actions">
          <button
            class="btn btn-primary"
            onclick={handleSaveSettings}
            disabled={!settingsChanged || savingSettings}
          >
            {savingSettings ? 'Saving...' : 'Save Settings'}
          </button>
        </div>
      </div>
    {/if}
  </div>
</div>

<style>
  .bk {
    max-width: var(--content-width);
  }

  /* ── Section layout ── */
  .bk-section {
    padding: var(--space-xl) 0;
    border-top: 1px solid var(--border);
  }

  .bk-label {
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: var(--dim);
    margin-bottom: var(--space-md);
  }

  .bk-desc {
    font-size: var(--text-sm);
    color: var(--sec);
    margin: 0 0 var(--space-md);
    line-height: 1.5;
  }

  /* ── Empty state ── */
  .bk-empty {
    padding: var(--space-xl) 0;
  }

  .bk-empty-text {
    font-size: var(--text-sm);
    color: var(--dim);
    margin: 0;
  }

  /* ── Table ── */
  .bk-table {
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    overflow: hidden;
  }

  .bk-table-header {
    display: grid;
    grid-template-columns: 1fr 80px 160px 160px;
    gap: var(--space-sm);
    padding: var(--space-sm) var(--space-md);
    background: var(--raised);
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--dim);
    border-bottom: 1px solid var(--border);
  }

  .bk-table-row {
    display: grid;
    grid-template-columns: 1fr 80px 160px 160px;
    gap: var(--space-sm);
    padding: var(--space-sm) var(--space-md);
    align-items: center;
    font-size: var(--text-sm);
    color: var(--text);
    border-bottom: 1px solid var(--border);
    transition: background 0.1s;
  }

  .bk-table-row:last-child {
    border-bottom: none;
  }

  .bk-table-row:hover {
    background: var(--raised);
  }

  .bk-filename {
    font-family: var(--font-mono);
    font-size: 12px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .bk-col-size,
  .bk-col-date {
    color: var(--sec);
    font-size: 13px;
  }

  .bk-col-actions {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
  }

  .bk-action {
    background: none;
    border: none;
    padding: 0;
    font-size: 13px;
    font-weight: 500;
    color: var(--purple);
    cursor: pointer;
    font-family: var(--font);
    transition: opacity 0.1s;
  }

  .bk-action:hover {
    opacity: 0.7;
  }

  .bk-action--danger {
    color: var(--danger);
  }

  /* ── Restore ── */
  .bk-restore-row {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
    margin-bottom: var(--space-md);
  }

  .bk-file-input {
    font-size: var(--text-sm);
    color: var(--sec);
  }

  .bk-file-input::file-selector-button {
    padding: 6px 14px;
    border-radius: var(--radius-sm);
    border: 1px solid var(--border);
    background: var(--bg);
    color: var(--text);
    font-size: 13px;
    font-weight: 500;
    cursor: pointer;
    font-family: var(--font);
    margin-right: var(--space-sm);
    transition: background 0.1s, border-color 0.1s;
  }

  .bk-file-input::file-selector-button:hover {
    background: var(--raised);
    border-color: var(--border);
  }

  .bk-file-name {
    font-size: 13px;
    color: var(--sec);
    font-family: var(--font-mono);
  }

  .bk-restore-actions {
    margin-bottom: var(--space-md);
  }

  .bk-confirm-banner {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    padding: 10px 14px;
    margin-bottom: var(--space-md);
    background: #fffbeb;
    border: 1px solid #f5e6b8;
    border-radius: var(--radius-sm);
    font-size: 13px;
    color: #92400e;
    line-height: 1.5;
  }

  .bk-confirm-banner svg {
    flex-shrink: 0;
    margin-top: 2px;
  }

  :global(.dark) .bk-confirm-banner {
    background: #332b10;
    border-color: #4a3f1a;
    color: #fbbf24;
  }

  .bk-warning {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    padding: 10px 14px;
    background: var(--raised);
    border-radius: var(--radius-sm);
    font-size: 12px;
    color: var(--dim);
    line-height: 1.5;
  }

  .bk-warning svg {
    flex-shrink: 0;
    margin-top: 1px;
    opacity: 0.5;
  }

  /* ── Settings ── */
  .bk-settings-loading {
    font-size: var(--text-sm);
    color: var(--dim);
    padding: var(--space-md) 0;
  }

  .bk-toggle-row {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
    font-size: var(--text-sm);
    color: var(--text);
    cursor: pointer;
    margin-bottom: var(--space-md);
  }

  .bk-toggle-row input[type="checkbox"] {
    width: 16px;
    height: 16px;
    accent-color: var(--purple);
    cursor: pointer;
  }

  .bk-settings-fields {
    display: flex;
    gap: var(--space-lg);
    margin-bottom: var(--space-lg);
    flex-wrap: wrap;
  }

  .bk-field {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
  }

  .bk-field-label {
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--dim);
  }

  .bk-select {
    padding: 6px 28px 6px 10px;
    border-radius: var(--radius-sm);
    border: 1px solid transparent;
    background: var(--bg);
    color: var(--text);
    font-size: var(--text-sm);
    font-family: var(--font);
    cursor: pointer;
    appearance: auto;
    transition: border-color 0.1s;
  }

  .bk-select:hover,
  .bk-select:focus {
    border-color: var(--border);
    outline: none;
  }

  .bk-number-input {
    width: 64px;
    padding: 6px 10px;
    border-radius: var(--radius-sm);
    border: 1px solid transparent;
    background: var(--bg);
    color: var(--text);
    font-size: var(--text-sm);
    font-family: var(--font);
    transition: border-color 0.1s;
  }

  .bk-number-input:hover,
  .bk-number-input:focus {
    border-color: var(--border);
    outline: none;
  }

  .bk-field-hint {
    font-size: 13px;
    color: var(--dim);
  }

  .bk-settings-actions {
    margin-top: var(--space-md);
  }

  /* ── Responsive ── */
  @media (max-width: 640px) {
    .bk-table-header,
    .bk-table-row {
      grid-template-columns: 1fr;
      gap: 4px;
    }

    .bk-table-header {
      display: none;
    }

    .bk-table-row {
      padding: var(--space-md);
    }

    .bk-col-actions {
      padding-top: var(--space-xs);
    }

    .bk-settings-fields {
      flex-direction: column;
      gap: var(--space-md);
    }
  }
</style>
