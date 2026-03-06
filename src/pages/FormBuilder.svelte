<script>
  import { onMount } from 'svelte';
  import { formBuilder } from '$lib/api.js';
  import { currentFormId, navigate, addToast } from '$lib/stores.js';
  import FieldPalette from '$components/form-builder/FieldPalette.svelte';
  import FieldList from '$components/form-builder/FieldList.svelte';
  import FieldSettings from '$components/form-builder/FieldSettings.svelte';
  import FormSettings from '$components/form-builder/FormSettings.svelte';
  import FormPreview from '$components/form-builder/FormPreview.svelte';

  let formId = $derived($currentFormId);
  let loading = $state(true);
  let saving = $state(false);
  let form = $state(null);
  let fields = $state([]);
  let settings = $state({});
  let formName = $state('');
  let formSlug = $state('');
  let formStatus = $state('active');
  let selectedFieldIndex = $state(-1);
  let activeTab = $state('fields');
  let hasChanges = $state(false);

  let selectedField = $derived(selectedFieldIndex >= 0 && selectedFieldIndex < fields.length ? fields[selectedFieldIndex] : null);

  onMount(async () => {
    if (!formId) {
      navigate('forms-list');
      return;
    }
    await loadForm();
  });

  async function loadForm() {
    loading = true;
    try {
      const data = await formBuilder.get(formId);
      form = data.form;
      fields = data.form.fields || [];
      settings = data.form.settings || {};
      formName = data.form.name;
      formSlug = data.form.slug;
      formStatus = data.form.status;
      hasChanges = false;
    } catch (err) {
      addToast(err.message, 'error');
      navigate('forms-list');
    } finally {
      loading = false;
    }
  }

  function generateFieldId() {
    return 'f_' + Math.random().toString(36).substring(2, 9);
  }

  function slugifyField(text) {
    return text.toLowerCase().replace(/[^a-z0-9_]/g, '_').replace(/_+/g, '_').replace(/^_|_$/g, '');
  }

  function addField(type, label) {
    const name = slugifyField(label) || type;
    // Ensure unique name
    let uniqueName = name;
    let n = 1;
    while (fields.some(f => f.name === uniqueName)) {
      n++;
      uniqueName = name + '_' + n;
    }

    const newField = {
      id: generateFieldId(),
      type,
      label,
      name: uniqueName,
      placeholder: '',
      description: '',
      required: false,
      default_value: '',
      css_classes: '',
      validation: {},
      choices: [],
      conditions: [],
      settings: {},
    };

    fields = [...fields, newField];
    selectedFieldIndex = fields.length - 1;
    hasChanges = true;
  }

  function removeField(index) {
    fields = fields.filter((_, i) => i !== index);
    if (selectedFieldIndex === index) {
      selectedFieldIndex = -1;
    } else if (selectedFieldIndex > index) {
      selectedFieldIndex--;
    }
    hasChanges = true;
  }

  function reorderFields(oldIndex, newIndex) {
    const reordered = [...fields];
    const [moved] = reordered.splice(oldIndex, 1);
    reordered.splice(newIndex, 0, moved);
    fields = reordered;
    // Update selected index if needed
    if (selectedFieldIndex === oldIndex) {
      selectedFieldIndex = newIndex;
    } else if (selectedFieldIndex > oldIndex && selectedFieldIndex <= newIndex) {
      selectedFieldIndex--;
    } else if (selectedFieldIndex < oldIndex && selectedFieldIndex >= newIndex) {
      selectedFieldIndex++;
    }
    hasChanges = true;
  }

  function updateField(updatedField) {
    if (selectedFieldIndex < 0) return;
    fields = fields.map((f, i) => i === selectedFieldIndex ? updatedField : f);
    hasChanges = true;
  }

  function updateSettings(newSettings) {
    settings = newSettings;
    hasChanges = true;
  }

  async function save() {
    if (!formId) return;
    saving = true;
    try {
      await formBuilder.update(formId, {
        name: formName,
        slug: formSlug,
        fields,
        settings,
        status: formStatus,
      });
      hasChanges = false;
      addToast('Form saved');
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      saving = false;
    }
  }
</script>

{#if loading}
  <div class="loading-overlay"><div class="spinner"></div></div>
{:else}
  <div class="builder-page">
    <!-- Header bar -->
    <div class="builder-header">
      <button class="builder-back" onclick={() => navigate('forms-list')} title="Back to forms">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 16px; height: 16px;"><polyline points="15 18 9 12 15 6"/></svg>
      </button>
      <input class="builder-name-input" type="text" bind:value={formName} onchange={() => { hasChanges = true; }} placeholder="Form name" />
      <div class="builder-header-actions">
        <select class="builder-status-select" bind:value={formStatus} onchange={() => { hasChanges = true; }}>
          <option value="active">Active</option>
          <option value="draft">Draft</option>
          <option value="inactive">Inactive</option>
        </select>
        <button class="btn btn-primary" onclick={save} disabled={saving || !hasChanges}>
          {saving ? 'Saving...' : 'Save'}
        </button>
      </div>
    </div>

    <!-- Tabs -->
    <div class="builder-tabs">
      <button class="builder-tab" class:active={activeTab === 'fields'} onclick={() => { activeTab = 'fields'; }}>Fields</button>
      <button class="builder-tab" class:active={activeTab === 'settings'} onclick={() => { activeTab = 'settings'; }}>Settings</button>
      <button class="builder-tab" class:active={activeTab === 'preview'} onclick={() => { activeTab = 'preview'; }}>Preview</button>
    </div>

    <!-- Content -->
    {#if activeTab === 'fields'}
      <div class="builder-content">
        <div class="builder-palette">
          <FieldPalette onAddField={addField} />
        </div>
        <div class="builder-fields">
          <FieldList
            {fields}
            selectedIndex={selectedFieldIndex}
            onSelect={(i) => { selectedFieldIndex = i; }}
            onReorder={reorderFields}
            onRemove={removeField}
          />
        </div>
        <div class="builder-settings">
          <FieldSettings
            field={selectedField}
            onChange={updateField}
          />
        </div>
      </div>
    {:else if activeTab === 'settings'}
      <div class="builder-content-full">
        <FormSettings {settings} formSlug={formSlug} onChange={updateSettings} />
      </div>
    {:else if activeTab === 'preview'}
      <div class="builder-content-full">
        <FormPreview {fields} {settings} formSlug={formSlug} />
      </div>
    {/if}
  </div>
{/if}

<style>
  .builder-page {
    display: flex;
    flex-direction: column;
    height: 100%;
    overflow: hidden;
  }

  .builder-header {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 20px;
    border-bottom: 1px solid var(--border-color, #e5e7eb);
    background: var(--bg-surface, #fff);
    flex-shrink: 0;
  }

  .builder-back {
    background: none;
    border: none;
    padding: 4px;
    cursor: pointer;
    color: var(--text-secondary);
    display: flex;
    align-items: center;
    border-radius: var(--radius-md, 6px);
  }

  .builder-back:hover {
    background: var(--bg-hover, #f3f4f6);
    color: var(--text-primary);
  }

  .builder-name-input {
    flex: 1;
    font-size: 16px;
    font-weight: 600;
    border: 1px solid transparent;
    background: none;
    padding: 4px 8px;
    color: var(--text-primary);
    border-radius: var(--radius-md, 6px);
  }

  .builder-name-input:hover {
    border-color: var(--border-color, #e5e7eb);
  }

  .builder-name-input:focus {
    border-color: var(--accent-color, #2563eb);
    outline: none;
  }

  .builder-header-actions {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-shrink: 0;
  }

  .builder-status-select {
    padding: 5px 8px;
    font-size: 12px;
    border: 1px solid var(--border-color, #e5e7eb);
    border-radius: var(--radius-md, 6px);
    background: var(--input-bg, #fff);
    color: var(--text-primary);
  }

  .builder-tabs {
    display: flex;
    gap: 0;
    border-bottom: 1px solid var(--border-color, #e5e7eb);
    background: var(--bg-surface, #fff);
    padding: 0 20px;
    flex-shrink: 0;
  }

  .builder-tab {
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

  .builder-tab:hover {
    color: var(--text-primary);
  }

  .builder-tab.active {
    color: var(--text-primary);
    border-bottom-color: var(--accent-color, #2563eb);
  }

  .builder-content {
    display: flex;
    flex: 1;
    overflow: hidden;
  }

  .builder-palette {
    width: 200px;
    border-right: 1px solid var(--border-color, #e5e7eb);
    background: var(--bg-surface, #fff);
    flex-shrink: 0;
    overflow-y: auto;
  }

  .builder-fields {
    flex: 1;
    overflow-y: auto;
    background: var(--bg-subtle, #f9fafb);
  }

  .builder-settings {
    width: 300px;
    border-left: 1px solid var(--border-color, #e5e7eb);
    background: var(--bg-surface, #fff);
    flex-shrink: 0;
    overflow-y: auto;
  }

  .builder-content-full {
    flex: 1;
    overflow-y: auto;
    background: var(--bg-subtle, #f9fafb);
  }
</style>
