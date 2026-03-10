<script>
  import { onMount } from 'svelte';
  import { customizer as customizerApi } from '$lib/api.js';
  import { addToast, navigate } from '$lib/stores.js';
  import ColorField from '$components/customizer/ColorField.svelte';
  import FontField from '$components/customizer/FontField.svelte';
  import ImageField from '$components/customizer/ImageField.svelte';
  import CustomizerPreview from '$components/customizer/CustomizerPreview.svelte';

  let schema = $state(null);
  let values = $state({});
  let savedValues = $state({});
  let themeName = $state('');
  let loading = $state(true);
  let saving = $state(false);
  let activeSection = $state('');

  let isDirty = $derived(JSON.stringify(values) !== JSON.stringify(savedValues));

  onMount(async () => {
    try {
      const data = await customizerApi.get();
      schema = data.schema;
      values = data.values || {};
      savedValues = JSON.parse(JSON.stringify(data.values || {}));
      themeName = data.theme || '';
      if (schema?.sections?.length) {
        activeSection = schema.sections[0].id;
      }
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      loading = false;
    }
  });

  function handleFieldChange(sectionId, key, newValue) {
    values = {
      ...values,
      [sectionId]: {
        ...(values[sectionId] || {}),
        [key]: newValue,
      },
    };
  }

  async function handleSave() {
    saving = true;
    try {
      await customizerApi.save(values);
      savedValues = JSON.parse(JSON.stringify(values));
      addToast('Customizations saved', 'success');
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      saving = false;
    }
  }

  async function handleReset() {
    if (!confirm('Reset all customizations to theme defaults? This cannot be undone.')) return;
    try {
      await customizerApi.reset();
      const data = await customizerApi.get();
      schema = data.schema;
      values = data.values || {};
      savedValues = JSON.parse(JSON.stringify(data.values || {}));
      addToast('Reset to defaults', 'success');
    } catch (err) {
      addToast(err.message, 'error');
    }
  }

  async function handleExport() {
    try {
      const data = await customizerApi.exportPreset();
      const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = `outpost-customizer-${themeName}-${new Date().toISOString().slice(0, 10)}.json`;
      a.click();
      URL.revokeObjectURL(url);
      addToast('Preset exported', 'success');
    } catch (err) {
      addToast(err.message, 'error');
    }
  }

  function handleImportClick() {
    const input = document.createElement('input');
    input.type = 'file';
    input.accept = '.json';
    input.onchange = async (e) => {
      const file = e.target.files?.[0];
      if (!file) return;
      try {
        const text = await file.text();
        const data = JSON.parse(text);
        const importValues = data.values || data;
        await customizerApi.importPreset(importValues);
        // Reload
        const fresh = await customizerApi.get();
        values = fresh.values || {};
        savedValues = JSON.parse(JSON.stringify(fresh.values || {}));
        addToast('Preset imported', 'success');
      } catch (err) {
        addToast(err.message || 'Invalid preset file', 'error');
      }
    };
    input.click();
  }

  function getFieldDefault(sectionId, fieldKey) {
    if (!schema) return '';
    const section = schema.sections.find(s => s.id === sectionId);
    if (!section) return '';
    const field = section.fields.find(f => f.key === fieldKey);
    return field?.brand_default || field?.default || '';
  }
</script>

{#if loading}
  <div class="loading-overlay"><div class="spinner"></div></div>
{:else if !schema}
  <div class="customizer-unsupported">
    <div class="page-header">
      <div class="page-header-icon sage">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M18.37 2.63a2.12 2.12 0 0 1 3 3L14 13l-4 1 1-4 7.37-7.37z"/><path d="M9 14c-1.5 1.5-3 3.5-3 5a2 2 0 0 0 4 0c0-1.5-1.5-3.5-1-5"/></svg>
      </div>
      <div class="page-header-content">
        <h1 class="page-title">Customize</h1>
        <p class="page-subtitle">This theme doesn't support customization yet</p>
      </div>
    </div>
    <p class="unsupported-text">
      The active theme (<strong>{themeName}</strong>) does not include a <code>customizer</code> section in its <code>theme.json</code>.
    </p>
    <button class="btn btn-secondary" onclick={() => navigate('themes')}>Back to Themes</button>
  </div>
{:else}
  <div class="customizer-layout">
    <!-- Controls Panel -->
    <div class="customizer-controls">
      <div class="controls-header">
        <button class="controls-back" onclick={() => navigate('themes')} title="Back to Themes">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><polyline points="15 18 9 12 15 6"/></svg>
        </button>
        <div class="controls-title">
          <div class="controls-title-text">Customize</div>
          <div class="controls-theme-name">{themeName}</div>
        </div>
      </div>

      <!-- Section Tabs -->
      <div class="section-tabs">
        {#each schema.sections as section}
          <button
            class="section-tab"
            class:active={activeSection === section.id}
            onclick={() => { activeSection = section.id; }}
          >
            {section.label}
          </button>
        {/each}
      </div>

      <!-- Fields -->
      <div class="controls-body">
        {#each schema.sections as section}
          {#if activeSection === section.id}
            <div class="section-fields">
              {#each section.fields as field}
                {#if field.type === 'color'}
                  <ColorField
                    key={field.key}
                    label={field.label}
                    value={values[section.id]?.[field.key] ?? ''}
                    defaultValue={field.brand_default || field.default || '#000000'}
                    onchange={(k, v) => handleFieldChange(section.id, k, v)}
                  />
                {:else if field.type === 'font'}
                  <FontField
                    key={field.key}
                    label={field.label}
                    value={values[section.id]?.[field.key] ?? ''}
                    defaultValue={field.brand_default || field.default || 'Inter'}
                    onchange={(k, v) => handleFieldChange(section.id, k, v)}
                  />
                {:else if field.type === 'image'}
                  <ImageField
                    key={field.key}
                    label={field.label}
                    value={values[section.id]?.[field.key] ?? ''}
                    onchange={(k, v) => handleFieldChange(section.id, k, v)}
                  />
                {/if}
              {/each}
            </div>
          {/if}
        {/each}
      </div>

      <!-- Footer Actions -->
      <div class="controls-footer">
        <div class="footer-primary">
          <button
            class="btn btn-primary"
            onclick={handleSave}
            disabled={saving || !isDirty}
          >
            {saving ? 'Saving...' : isDirty ? 'Save Changes' : 'Saved'}
          </button>
          <button class="btn btn-secondary" onclick={handleReset}>
            Reset
          </button>
        </div>
        <div class="footer-secondary">
          <button class="footer-action" onclick={handleExport}>Export</button>
          <button class="footer-action" onclick={handleImportClick}>Import</button>
        </div>
      </div>
    </div>

    <!-- Preview Panel -->
    <div class="customizer-preview-panel">
      <CustomizerPreview {values} {schema} />
    </div>
  </div>
{/if}

<style>
  .customizer-layout {
    display: flex;
    height: 100%;
    min-height: 0;
  }

  /* Controls Panel */
  .customizer-controls {
    width: 380px;
    min-width: 380px;
    display: flex;
    flex-direction: column;
    border-right: 1px solid var(--border-color);
    background: var(--bg-primary);
    overflow: hidden;
  }

  .controls-header {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 16px 20px;
    border-bottom: 1px solid var(--border-color);
    flex-shrink: 0;
  }

  .controls-back {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    padding: 0;
    background: none;
    border: none;
    border-radius: var(--radius-md);
    color: var(--text-secondary);
    cursor: pointer;
    transition: color 0.15s, background 0.15s;
    flex-shrink: 0;
  }

  .controls-back:hover {
    color: var(--text-primary);
    background: var(--bg-hover);
  }

  .controls-back svg {
    width: 16px;
    height: 16px;
  }

  .controls-title-text {
    font-size: 15px;
    font-weight: 600;
    color: var(--text-primary);
  }

  .controls-theme-name {
    font-size: 12px;
    color: var(--text-tertiary);
    text-transform: capitalize;
  }

  /* Section Tabs */
  .section-tabs {
    display: flex;
    gap: 0;
    padding: 0 20px;
    border-bottom: 1px solid var(--border-color);
    flex-shrink: 0;
  }

  .section-tab {
    padding: 10px 0;
    margin-right: 20px;
    font-size: 13px;
    font-weight: 500;
    color: var(--text-tertiary);
    background: none;
    border: none;
    border-bottom: 2px solid transparent;
    cursor: pointer;
    transition: color 0.15s, border-color 0.15s;
  }

  .section-tab:hover {
    color: var(--text-primary);
  }

  .section-tab.active {
    color: var(--text-primary);
    border-bottom-color: var(--accent);
  }

  /* Fields Body */
  .controls-body {
    flex: 1;
    overflow-y: auto;
    padding: 8px 20px;
  }

  .section-fields {
    padding: 4px 0;
  }

  /* Footer */
  .controls-footer {
    flex-shrink: 0;
    padding: 16px 20px;
    border-top: 1px solid var(--border-color);
    background: var(--bg-primary);
  }

  .footer-primary {
    display: flex;
    gap: 8px;
    margin-bottom: 8px;
  }

  .footer-primary .btn-primary {
    flex: 1;
  }

  .footer-secondary {
    display: flex;
    gap: 12px;
    justify-content: center;
  }

  .footer-action {
    font-size: 12px;
    color: var(--text-tertiary);
    background: none;
    border: none;
    cursor: pointer;
    padding: 2px 0;
    text-decoration: underline;
    text-underline-offset: 2px;
    transition: color 0.15s;
  }

  .footer-action:hover {
    color: var(--text-primary);
  }

  /* Preview Panel */
  .customizer-preview-panel {
    flex: 1;
    min-width: 0;
    height: 100%;
  }

  /* Unsupported State */
  .customizer-unsupported {
    max-width: var(--content-width-narrow);
    padding: 40px 48px;
  }

  .unsupported-text {
    font-size: var(--font-size-sm);
    color: var(--text-secondary);
    margin-bottom: var(--space-lg);
    line-height: 1.6;
  }

  .unsupported-text code {
    font-size: var(--font-size-xs);
    background: var(--bg-hover);
    padding: 2px 6px;
    border-radius: var(--radius-sm);
  }

  /* Mobile */
  @media (max-width: 768px) {
    .customizer-layout {
      flex-direction: column;
    }

    .customizer-controls {
      width: 100%;
      min-width: 0;
      border-right: none;
      border-bottom: 1px solid var(--border-color);
      max-height: 50vh;
    }

    .customizer-preview-panel {
      height: 50vh;
    }
  }
</style>
