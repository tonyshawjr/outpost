<script>
  import { code as codeApi } from '$lib/api.js';

  let { themeFolder = '', onCreated, onCancel } = $props();

  let themeName = $state(themeFolder.replace(/[-_]/g, ' ').replace(/\b\w/g, c => c.toUpperCase()));
  let author    = $state('');
  let desc      = $state('');
  let creating  = $state(false);

  let inputEl = $state(null);

  $effect(() => {
    if (inputEl) inputEl.focus();
  });

  async function handleSubmit() {
    if (!themeName.trim() || creating) return;
    creating = true;

    const themeJson = JSON.stringify({
      "$schema": "/outpost/docs/schemas/theme.schema.json",
      name: themeName.trim(),
      version: "1.0.0",
      author: author.trim() || undefined,
      description: desc.trim() || undefined,
      screenshot: "",
      managed: false,
    }, null, 4);

    try {
      await codeApi.create(themeFolder + '/theme.json', 'file', themeJson);
      onCreated(themeFolder);
    } catch (err) {
      creating = false;
    }
  }

  function handleKeydown(e) {
    if (e.key === 'Enter') { e.preventDefault(); handleSubmit(); }
  }
</script>

<div class="forge-theme-wizard">
  <p class="forge-theme-intro">Create a <code>theme.json</code> to register this folder as an Outpost theme. Once created, you can activate it from Settings &rarr; Themes.</p>

  <div class="forge-field">
    <label class="forge-label">Theme name</label>
    <input
      class="forge-input"
      bind:this={inputEl}
      bind:value={themeName}
      onkeydown={handleKeydown}
      placeholder="My Theme"
      autocomplete="off"
    />
  </div>

  <div class="forge-field">
    <label class="forge-label">Author</label>
    <input
      class="forge-input"
      bind:value={author}
      onkeydown={handleKeydown}
      placeholder="Your name or company"
      autocomplete="off"
    />
  </div>

  <div class="forge-field">
    <label class="forge-label">Description</label>
    <input
      class="forge-input"
      bind:value={desc}
      onkeydown={handleKeydown}
      placeholder="A short description of the theme"
      autocomplete="off"
    />
  </div>

  <div class="forge-actions">
    <button class="btn btn-secondary" onclick={onCancel}>Cancel</button>
    <button class="btn btn-primary" onclick={handleSubmit} disabled={!themeName.trim() || creating}>
      {creating ? 'Creating...' : 'Forge Theme'}
    </button>
  </div>
</div>

<style>
  .forge-theme-wizard { display: flex; flex-direction: column; gap: 16px; }
  .forge-theme-intro { font-size: 13px; color: var(--ce-muted, #888); line-height: 1.6; margin: 0; }
  .forge-theme-intro code { background: var(--ce-surface, #f0f0f0); padding: 1px 5px; border-radius: 3px; font-size: 12px; }
</style>
