<script>
  import { importApi } from '$lib/api.js';
  import { addToast } from '$lib/stores.js';

  let file = $state(null);
  let dragging = $state(false);
  let importing = $state(false);
  let result = $state(null);

  let statusFilter = $state('publish');
  let onDuplicate = $state('skip');
  let collectionSlug = $state('post');

  function onFileChange(e) {
    file = e.target.files[0] || null;
    result = null;
  }

  function onDrop(e) {
    e.preventDefault();
    dragging = false;
    file = e.dataTransfer.files[0] || null;
    result = null;
  }

  async function runImport() {
    if (!file) return;
    importing = true;
    result = null;
    try {
      result = await importApi.wordpress(file, {
        collection_slug: collectionSlug,
        status_filter: statusFilter,
        on_duplicate: onDuplicate,
      });
      if (result.success) {
        addToast(`Import complete — ${result.imported} imported, ${result.skipped} skipped`, 'success');
      }
    } catch (err) {
      addToast(err.message, 'error');
      result = { error: err.message };
    } finally {
      importing = false;
    }
  }
</script>

<div class="settings-section">
  <h3 class="settings-section-title">Import</h3>
  <p class="settings-section-desc">Import posts from a WordPress WXR export file.</p>

  <div class="import-card">
    <div class="import-row">
      <label class="form-label">Target collection</label>
      <input class="input" type="text" bind:value={collectionSlug} placeholder="post" style="max-width: 240px;" />
      <p class="form-hint">The collection slug where posts will be imported.</p>
    </div>

    <div class="import-row">
      <label class="form-label">Import status</label>
      <div class="radio-group">
        <label class="radio-option">
          <input type="radio" bind:group={statusFilter} value="publish" />
          Published posts only
        </label>
        <label class="radio-option">
          <input type="radio" bind:group={statusFilter} value="all" />
          All posts (including drafts)
        </label>
      </div>
    </div>

    <div class="import-row">
      <label class="form-label">If post already exists</label>
      <div class="radio-group">
        <label class="radio-option">
          <input type="radio" bind:group={onDuplicate} value="skip" />
          Skip (keep existing)
        </label>
        <label class="radio-option">
          <input type="radio" bind:group={onDuplicate} value="overwrite" />
          Overwrite with imported data
        </label>
      </div>
    </div>

    <!-- svelte-ignore a11y_no_static_element_interactions -->
    <div
      class="drop-zone"
      class:dragging
      ondragover={(e) => { e.preventDefault(); dragging = true; }}
      ondragleave={() => { dragging = false; }}
      ondrop={onDrop}
    >
      {#if file}
        <div class="drop-zone-file">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="20" height="20"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
          {file.name}
          <button class="drop-zone-clear" onclick={() => { file = null; result = null; }}>✕</button>
        </div>
      {:else}
        <label class="drop-zone-label">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="24" height="24"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
          <span>Drop your WordPress .xml export here, or <u>browse</u></span>
          <input type="file" accept=".xml,text/xml,application/xml" onchange={onFileChange} hidden />
        </label>
      {/if}
    </div>

    <div class="import-actions">
      <button class="btn btn-primary" onclick={runImport} disabled={!file || importing}>
        {importing ? 'Importing…' : 'Run import'}
      </button>
    </div>

    {#if result}
      {#if result.error}
        <div class="import-result error">
          <strong>Error:</strong> {result.error}
        </div>
      {:else}
        <div class="import-result success">
          <div class="result-row"><span class="result-label">Imported</span><span class="result-val">{result.imported}</span></div>
          <div class="result-row"><span class="result-label">Overwritten</span><span class="result-val">{result.overwritten}</span></div>
          <div class="result-row"><span class="result-label">Skipped</span><span class="result-val">{result.skipped}</span></div>
          {#if result.errors?.length}
            <details class="result-errors">
              <summary>{result.errors.length} warning(s)</summary>
              <ul>{#each result.errors as e}<li>{e}</li>{/each}</ul>
            </details>
          {/if}
        </div>
      {/if}
    {/if}
  </div>
</div>

<style>
  .import-card {
    max-width: 600px;
    display: flex;
    flex-direction: column;
    gap: 28px;
  }
  .import-row { display: flex; flex-direction: column; gap: 6px; }
  .form-hint { font-size: 12px; color: var(--text-tertiary); margin: 0; }
  .radio-group { display: flex; flex-direction: column; gap: 8px; }
  .radio-option { display: flex; align-items: center; gap: 8px; font-size: 14px; cursor: pointer; color: var(--text-primary); }
  .drop-zone {
    border: 1.5px dashed var(--border-secondary);
    border-radius: 10px; padding: 36px 24px; text-align: center;
    transition: border-color 0.15s, background 0.15s; background: transparent;
  }
  .drop-zone.dragging { border-color: var(--accent); background: color-mix(in srgb, var(--accent) 5%, transparent); }
  .drop-zone-label { display: flex; flex-direction: column; align-items: center; gap: 10px; cursor: pointer; font-size: 14px; color: var(--text-tertiary); }
  .drop-zone-file { display: flex; align-items: center; justify-content: center; gap: 8px; font-size: 14px; color: var(--text-primary); }
  .drop-zone-clear { background: none; border: none; cursor: pointer; color: var(--text-tertiary); padding: 0 4px; font-size: 14px; line-height: 1; }
  .drop-zone-clear:hover { color: var(--text-primary); }
  .import-actions { display: flex; gap: 12px; }
  .import-result { padding: 16px 20px; border-radius: 8px; font-size: 14px; }
  .import-result.success { background: color-mix(in srgb, var(--success) 8%, transparent); border: 1px solid color-mix(in srgb, var(--success) 20%, transparent); }
  .import-result.error { background: color-mix(in srgb, var(--error) 8%, transparent); border: 1px solid color-mix(in srgb, var(--error) 20%, transparent); }
  .result-row { display: flex; justify-content: space-between; padding: 4px 0; }
  .result-label { color: var(--text-tertiary); }
  .result-val { font-weight: 600; }
  .result-errors { margin-top: 12px; font-size: 13px; }
  .result-errors summary { cursor: pointer; color: var(--text-tertiary); }
  .result-errors ul { margin: 8px 0 0 16px; }
  .result-errors li { margin-bottom: 4px; }
</style>
