<script>
  let {
    pageData = null,
    pageId = null,
    themeSlug = '',
    apiUrl = '/outpost/api.php',
    csrfToken = '',
    showToast = () => {},
  } = $props();

  let visibility = $state('public');
  let status = $state('published');
  let template = $state('');
  let saving = $state(false);

  $effect(() => {
    if (pageData) {
      visibility = pageData.visibility || 'public';
      status = pageData.status || 'published';
      template = pageData.template || '';
    }
  });

  async function save() {
    saving = true;
    try {
      const resp = await fetch(apiUrl + '?action=pages&id=' + pageId, {
        method: 'PUT',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': csrfToken,
        },
        credentials: 'include',
        body: JSON.stringify({ visibility, status }),
      });
      if (resp.ok) {
        showToast('Settings saved');
      } else {
        showToast('Failed to save settings');
      }
    } catch {
      showToast('Failed to save settings');
    } finally {
      saving = false;
    }
  }
</script>

<div class="ope-settings-drawer">
  <div class="ope-settings-section">
    <label class="ope-settings-label">Status</label>
    <select class="ope-settings-select" bind:value={status}>
      <option value="published">Published</option>
      <option value="draft">Draft</option>
    </select>
  </div>

  <div class="ope-settings-section">
    <label class="ope-settings-label">Visibility</label>
    <select class="ope-settings-select" bind:value={visibility}>
      <option value="public">Public</option>
      <option value="members">Members only</option>
      <option value="paid">Paid members only</option>
    </select>
  </div>

  <div class="ope-settings-section">
    <label class="ope-settings-label">Template</label>
    <div class="ope-settings-value">{template || 'Default'}</div>
  </div>

  {#if themeSlug}
    <div class="ope-settings-section">
      <label class="ope-settings-label">Theme</label>
      <div class="ope-settings-value">{themeSlug}</div>
    </div>
  {/if}

  <div class="ope-settings-section">
    <label class="ope-settings-label">Path</label>
    <div class="ope-settings-value ope-settings-mono">{pageData?.path || window.location.pathname}</div>
  </div>

  <div class="ope-settings-actions">
    <button class="ope-settings-save" onclick={save} disabled={saving}>
      {saving ? 'Saving...' : 'Save Settings'}
    </button>
  </div>
</div>

<style>
  .ope-settings-drawer {
    padding: 20px;
    display: flex;
    flex-direction: column;
    gap: 24px;
  }
  .ope-settings-section {
    display: flex;
    flex-direction: column;
    gap: 6px;
  }
  .ope-settings-label {
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: #9CA3AF;
  }
  .ope-settings-select {
    width: 100%;
    padding: 8px 0;
    border: none;
    border-bottom: 1px solid transparent;
    font-size: 14px;
    color: #111827;
    background: transparent;
    outline: none;
    cursor: pointer;
    font-family: inherit;
    transition: border-color 0.15s;
    -webkit-appearance: none;
  }
  .ope-settings-select:hover { border-bottom-color: #E5E7EB; }
  .ope-settings-select:focus { border-bottom-color: #2D5A47; }
  .ope-settings-value {
    font-size: 14px;
    color: #374151;
    padding: 8px 0;
  }
  .ope-settings-mono {
    font-family: 'SF Mono', 'Monaco', 'Menlo', monospace;
    font-size: 13px;
    color: #6B7280;
  }
  .ope-settings-actions { padding-top: 8px; }
  .ope-settings-save {
    width: 100%;
    padding: 10px;
    background: #2D5A47;
    color: white;
    border: none;
    border-radius: 6px;
    font-size: 13px;
    font-weight: 500;
    cursor: pointer;
    transition: background 0.15s;
  }
  .ope-settings-save:hover:not(:disabled) { background: #1E4535; }
  .ope-settings-save:disabled { opacity: 0.5; cursor: default; }
</style>
