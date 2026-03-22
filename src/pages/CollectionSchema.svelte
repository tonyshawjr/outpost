<script>
  import { onMount, tick } from 'svelte';
  import { collections as collectionsApi, workflows as workflowsApi } from '$lib/api.js';
  import { currentCollectionSlug, collectionsList, navigate, addToast } from '$lib/stores.js';
  import { slugify } from '$lib/utils.js';

  let collSlug = $derived($currentCollectionSlug);
  let collection = $state(null);
  let loading = $state(true);
  let saving = $state(false);
  let availableWorkflows = $state([]);
  let allCollections = $derived($collectionsList);

  // Collection settings
  let formName = $state('');
  let formSlug = $state('');
  let formSingularName = $state('');
  let formUrlPattern = $state('');
  let formRequireReview = $state(false);
  let formWorkflowId = $state(null);
  let formSortField = $state('created_at');
  let formSortDirection = $state('DESC');
  let formItemsPerPage = $state(10);

  // Schema fields
  let formSchema = $state([]);

  // Selected field for right panel (repeater/complex)
  let selectedFieldIndex = $state(null);
  let selectedField = $derived(selectedFieldIndex !== null ? formSchema[selectedFieldIndex] : null);

  // Lodge settings
  let formLodgeEnabled = $state(false);
  let formLodgeConfig = $state({
    allow_create: true,
    allow_edit: true,
    allow_delete: false,
    require_approval: false,
    max_items_per_member: 0,
    editable_fields: [],
    readonly_fields: [],
  });

  // Sortable
  let fieldListEl = $state(null);
  let sortableInstance = $state(null);

  // Repeater visual builder toggle per field index
  let repeaterJsonMode = $state({});

  // Advanced section collapsed by default
  let advancedOpen = $state(false);

  // Expanded field for inline settings (non-complex fields)
  let expandedFields = $state({});

  // Overflow menu
  let showOverflow = $state(false);

  // Max fields
  const MAX_FIELDS = 20;

  // Badge colors by type
  const typeBadgeColors = {
    text: { bg: 'var(--bg-tertiary)', color: 'var(--text-secondary)' },
    textarea: { bg: 'var(--bg-tertiary)', color: 'var(--text-secondary)' },
    richtext: { bg: '#F3EEFA', color: '#7B5EA7' },
    image: { bg: '#E8F5F0', color: '#2D7A5F' },
    date: { bg: 'var(--warning-soft)', color: 'var(--warning)' },
    number: { bg: 'var(--sage-light)', color: 'var(--sage)' },
    toggle: { bg: 'var(--success-soft)', color: 'var(--success)' },
    select: { bg: '#FAF0E6', color: '#A67C52' },
    color: { bg: 'var(--clay-light)', color: 'var(--clay)' },
    link: { bg: 'var(--accent-soft)', color: 'var(--accent)' },
    repeater: { bg: '#E8EFF8', color: '#4A6FA5' },
    gallery: { bg: '#EEF3E8', color: '#5C7A3A' },
    folder: { bg: 'var(--sage-light)', color: 'var(--sage)' },
    relationship: { bg: 'var(--accent-soft)', color: 'var(--accent)' },
    flexible: { bg: 'var(--warning-soft)', color: 'var(--warning)' },
  };

  const typeLabels = {
    text: 'Text', textarea: 'Textarea', richtext: 'Rich Text', image: 'Image',
    date: 'Date', number: 'Number', toggle: 'Toggle', select: 'Select',
    color: 'Color', link: 'Link', repeater: 'Repeater', gallery: 'Gallery',
    folder: 'Folder', relationship: 'Relationship', flexible: 'Flexible',
  };

  // Fields that show the right panel when selected
  const complexTypes = ['repeater', 'flexible', 'relationship'];

  function isComplexField(field) {
    return complexTypes.includes(field?.type);
  }

  function selectField(i) {
    if (isComplexField(formSchema[i])) {
      // If already selected, deselect
      if (selectedFieldIndex === i) {
        selectedFieldIndex = null;
      } else {
        selectedFieldIndex = i;
        expandedFields = { ...expandedFields, [i]: false };
      }
    } else {
      // Toggle inline expansion for non-complex fields
      selectedFieldIndex = null;
      expandedFields = { ...expandedFields, [i]: !expandedFields[i] };
    }
  }

  function openFieldPanel(i, e) {
    e.stopPropagation();
    if (isComplexField(formSchema[i])) {
      selectedFieldIndex = selectedFieldIndex === i ? null : i;
    } else {
      expandedFields = { ...expandedFields, [i]: !expandedFields[i] };
    }
  }

  function slugifyField(text) {
    return text.toLowerCase().replace(/[^a-z0-9_]/g, '_').replace(/_+/g, '_').replace(/^_|_$/g, '');
  }

  function autoFieldName(i) {
    const prev = formSchema[i - 1];
    if (!formSchema[i].name || formSchema[i].name === slugifyField(prev?.label || '')) {
      formSchema[i].name = slugifyField(formSchema[i].label);
    }
  }

  function addSchemaField() {
    formSchema = [...formSchema, { name: '', type: 'text', label: '', required: false, placeholder: '', description: '', defaultValue: '', choices: '', repeaterFields: '[]', repeaterVisual: [], flexLayouts: '', relCollection: '', relMultiple: true, relMax: 0, conditions: [] }];
    // expand the new field inline
    const idx = formSchema.length - 1;
    expandedFields = { ...expandedFields, [idx]: true };
  }

  function removeSchemaField(i) {
    if (selectedFieldIndex === i) selectedFieldIndex = null;
    if (selectedFieldIndex !== null && selectedFieldIndex > i) selectedFieldIndex--;
    formSchema = formSchema.filter((_, idx) => idx !== i);
  }

  // Repeater visual sub-field helpers
  function getRepeaterVisual(field) {
    if (field.repeaterVisual && field.repeaterVisual.length > 0) return field.repeaterVisual;
    try {
      const parsed = JSON.parse(field.repeaterFields || '[]');
      if (Array.isArray(parsed) && parsed.length > 0) return parsed.map(f => ({ ...f }));
    } catch {}
    return [];
  }

  function addRepeaterSubField(fieldIndex) {
    const visual = getRepeaterVisual(formSchema[fieldIndex]);
    visual.push({ name: '', type: 'text', label: '', options: [] });
    formSchema[fieldIndex].repeaterVisual = [...visual];
    syncRepeaterVisualToJson(fieldIndex);
  }

  function removeRepeaterSubField(fieldIndex, subIndex) {
    const visual = getRepeaterVisual(formSchema[fieldIndex]);
    visual.splice(subIndex, 1);
    formSchema[fieldIndex].repeaterVisual = [...visual];
    syncRepeaterVisualToJson(fieldIndex);
  }

  function syncRepeaterVisualToJson(fieldIndex) {
    const visual = formSchema[fieldIndex].repeaterVisual || [];
    const clean = visual.map(f => {
      const entry = { name: f.name || slugifyField(f.label || ''), type: f.type || 'text', label: f.label || f.name || '' };
      if (f.type === 'select' && f.options && f.options.length > 0) {
        entry.options = typeof f.options === 'string' ? f.options.split(',').map(o => o.trim()).filter(Boolean) : f.options;
      }
      return entry;
    }).filter(f => f.name);
    formSchema[fieldIndex].repeaterFields = JSON.stringify(clean, null, 2);
  }

  function syncRepeaterJsonToVisual(fieldIndex) {
    try {
      const parsed = JSON.parse(formSchema[fieldIndex].repeaterFields || '[]');
      if (Array.isArray(parsed)) {
        formSchema[fieldIndex].repeaterVisual = parsed.map(f => ({
          ...f,
          options: Array.isArray(f.options) ? f.options : [],
        }));
      }
    } catch {}
  }

  function autoSubFieldName(fieldIndex, subIndex) {
    const visual = formSchema[fieldIndex].repeaterVisual;
    if (visual && visual[subIndex]) {
      const sub = visual[subIndex];
      if (!sub.name || sub.name === slugifyField(visual[subIndex - 1]?.label || '')) {
        sub.name = slugifyField(sub.label);
      }
      syncRepeaterVisualToJson(fieldIndex);
    }
  }

  onMount(async () => {
    await loadData();
    await loadWorkflows();
  });

  async function loadWorkflows() {
    try {
      const data = await workflowsApi.list();
      availableWorkflows = data.workflows || [];
    } catch (e) {}
  }

  async function loadData() {
    loading = true;
    try {
      const data = await collectionsApi.list();
      const colls = data.collections || [];
      collectionsList.set(colls);
      collection = colls.find(c => c.slug === collSlug);
      if (!collection) {
        addToast('Collection not found', 'error');
        navigate('collections');
        return;
      }
      populateForm(collection);
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      loading = false;
    }
    await tick();
    initSortable();
  }

  function populateForm(coll) {
    formName = coll.name;
    formSlug = coll.slug;
    formSingularName = coll.singular_name || coll.name;
    formUrlPattern = coll.url_pattern || `/${coll.slug}/{slug}`;
    formRequireReview = !!(coll.require_review);
    formWorkflowId = coll.workflow_id || null;
    formSortField = coll.sort_field || 'created_at';
    formSortDirection = (coll.sort_direction || 'DESC').toUpperCase();
    formItemsPerPage = coll.items_per_page || 10;

    let schema = {};
    try { schema = JSON.parse(coll.schema || '{}'); } catch { schema = {}; }
    formSchema = Object.entries(schema).map(([name, def]) => {
      let repeaterVisual = [];
      if (def.fields) {
        const arr = Array.isArray(def.fields) ? def.fields : [];
        repeaterVisual = arr.map(f => ({ ...f, options: Array.isArray(f.options) ? f.options : [] }));
      }
      return {
        name,
        type: def.type || 'text',
        label: def.label || name,
        required: def.required || false,
        placeholder: def.placeholder || '',
        description: def.description || '',
        defaultValue: def.default || '',
        choices: def.choices || '',
        relCollection: def.collection || '',
        relMultiple: def.multiple !== false,
        relMax: def.max || 0,
        flexLayouts: def.layouts ? JSON.stringify(def.layouts, null, 2) : '',
        repeaterFields: def.fields ? JSON.stringify(def.fields, null, 2) : '[]',
        repeaterVisual,
        conditions: def.conditions || [],
      };
    });
    if (formSchema.length === 0) {
      formSchema = [{ name: '', type: 'text', label: '', required: false, placeholder: '', description: '', defaultValue: '', choices: '', repeaterFields: '[]', repeaterVisual: [], flexLayouts: '', relCollection: '', relMultiple: true, relMax: 0, conditions: [] }];
    }

    formLodgeEnabled = !!(coll.lodge_enabled);
    try {
      const lc = typeof coll.lodge_config === 'string' ? JSON.parse(coll.lodge_config || '{}') : (coll.lodge_config || {});
      formLodgeConfig = {
        allow_create: lc.allow_create !== false,
        allow_edit: lc.allow_edit !== false,
        allow_delete: !!lc.allow_delete,
        require_approval: !!lc.require_approval,
        max_items_per_member: lc.max_items_per_member || 0,
        editable_fields: lc.editable_fields || [],
        readonly_fields: lc.readonly_fields || [],
      };
    } catch {
      formLodgeConfig = { allow_create: true, allow_edit: true, allow_delete: false, require_approval: false, max_items_per_member: 0, editable_fields: [], readonly_fields: [] };
    }
  }

  async function initSortable() {
    if (!fieldListEl) return;
    const { default: Sortable } = await import('sortablejs');
    if (sortableInstance) sortableInstance.destroy();
    sortableInstance = new Sortable(fieldListEl, {
      animation: 150,
      handle: '.sf-drag-handle',
      ghostClass: 'sf-sortable-ghost',
      onEnd(evt) {
        if (evt.oldIndex !== evt.newIndex) {
          const arr = [...formSchema];
          const [moved] = arr.splice(evt.oldIndex, 1);
          arr.splice(evt.newIndex, 0, moved);
          formSchema = arr;
          // Update selectedFieldIndex if it was moved
          if (selectedFieldIndex === evt.oldIndex) {
            selectedFieldIndex = evt.newIndex;
          } else if (selectedFieldIndex !== null) {
            if (evt.oldIndex < selectedFieldIndex && evt.newIndex >= selectedFieldIndex) {
              selectedFieldIndex--;
            } else if (evt.oldIndex > selectedFieldIndex && evt.newIndex <= selectedFieldIndex) {
              selectedFieldIndex++;
            }
          }
        }
      },
    });
  }

  $effect(() => {
    if (fieldListEl && formSchema.length >= 0) {
      tick().then(() => initSortable());
    }
  });

  function buildSchema() {
    const schema = {};
    for (const f of formSchema) {
      const name = f.name.trim() || slugifyField(f.label);
      if (name) {
        const field = { type: f.type, label: f.label || name };
        if (f.required) field.required = true;
        if (f.placeholder) field.placeholder = f.placeholder;
        if (f.description) field.description = f.description;
        if (f.defaultValue) field.default = f.defaultValue;
        if (f.choices) field.choices = f.choices;
        if (f.type === 'relationship') {
          field.collection = f.relCollection || '';
          field.multiple = f.relMultiple !== false;
          field.max = parseInt(f.relMax) || 0;
        }
        if (f.type === 'repeater') {
          try { field.fields = JSON.parse(f.repeaterFields || '[]'); } catch { field.fields = []; }
        }
        if (f.type === 'flexible') {
          try { field.layouts = JSON.parse(f.flexLayouts || '{}'); } catch { field.layouts = {}; }
        }
        if (f.conditions && f.conditions.length > 0) {
          field.conditions = f.conditions;
        }
        schema[name] = field;
      }
    }
    return schema;
  }

  async function saveCollection() {
    if (!formName || !collection) return;
    saving = true;
    try {
      const schema = buildSchema();
      await collectionsApi.update(collection.id, {
        name: formName,
        singular_name: formSingularName || formName,
        schema,
        url_pattern: formUrlPattern || `/${formSlug}/{slug}`,
        require_review: formRequireReview ? 1 : 0,
        workflow_id: formWorkflowId || null,
        sort_field: formSortField,
        sort_direction: formSortDirection,
        items_per_page: formItemsPerPage,
        lodge_enabled: formLodgeEnabled ? 1 : 0,
        lodge_config: formLodgeConfig,
      });
      addToast('Collection saved', 'success');
      const data = await collectionsApi.list();
      collectionsList.set(data.collections || []);
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      saving = false;
    }
  }

  function goBack() {
    navigate('collections');
  }

  function handleKeyboardSave(e) {
    if ((e.metaKey || e.ctrlKey) && e.key === 's') {
      e.preventDefault();
      saveCollection();
    }
  }

  function handleDocClick() {
    if (showOverflow) showOverflow = false;
  }
</script>

<svelte:window onkeydown={handleKeyboardSave} />
<svelte:document onclick={handleDocClick} />

{#if loading}
  <div class="loading-overlay"><div class="spinner"></div></div>
{:else if collection}
  <div>
    <!-- Page Header -->
    <div class="page-header">
      <div class="page-header-icon sage">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
      </div>
      <div class="page-header-content">
        <h1 class="page-title">{formName || 'Collection Schema'}</h1>
        <p class="page-subtitle">
          <button class="sf-back-link" onclick={goBack} type="button">Collections</button>
          <span style="color: var(--text-light); margin: 0 4px;">/</span>
          <span>{formName}</span>
          <span style="color: var(--text-light); margin: 0 4px;">/</span>
          <span style="font-weight: 500; color: var(--text-secondary);">Schema</span>
        </p>
      </div>
      <div class="page-header-actions">
        <button class="btn btn-primary" onclick={saveCollection} disabled={saving || !formName}>
          {saving ? 'Saving...' : 'Save changes'}
        </button>
        <div class="sf-overflow-wrap">
          <button class="btn btn-ghost btn-sm" onclick={(e) => { e.stopPropagation(); showOverflow = !showOverflow; }} type="button" aria-label="More options">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="5" r="2"/><circle cx="12" cy="12" r="2"/><circle cx="12" cy="19" r="2"/></svg>
          </button>
          {#if showOverflow}
            <div class="sf-overflow-menu">
              <button class="sf-overflow-item" type="button" onclick={() => { advancedOpen = !advancedOpen; showOverflow = false; }}>
                {advancedOpen ? 'Hide' : 'Show'} Advanced Settings
              </button>
            </div>
          {/if}
        </div>
      </div>
    </div>

    <!-- Two-panel layout -->
    <div class="sf-layout" class:sf-layout-with-panel={selectedFieldIndex !== null}>
      <!-- LEFT PANEL -->
      <div class="sf-main">
        <!-- Collection Settings Card -->
        <div class="card sf-settings-card">
          <div class="sf-settings-grid-3">
            <div class="form-group" style="margin-bottom: 0;">
              <label class="sf-label" for="cs-name">Name</label>
              <input id="cs-name" class="input" type="text" bind:value={formName} placeholder="Blog Posts" />
            </div>
            <div class="form-group" style="margin-bottom: 0;">
              <label class="sf-label" for="cs-singular">Singular Name</label>
              <input id="cs-singular" class="input" type="text" bind:value={formSingularName} placeholder="Blog Post" />
            </div>
            <div class="form-group" style="margin-bottom: 0;">
              <label class="sf-label" for="cs-per-page">Per Page</label>
              <input id="cs-per-page" class="input" type="number" min="1" max="100" bind:value={formItemsPerPage} style="max-width: 90px;" />
            </div>
          </div>
          <div class="sf-settings-grid-3" style="margin-top: var(--space-md);">
            <div class="form-group" style="margin-bottom: 0;">
              <label class="sf-label">Slug</label>
              <div class="sf-slug-row">
                <code class="sf-slug-badge">{formSlug}</code>
                <span class="sf-slug-hint">Cannot be changed</span>
              </div>
            </div>
            <div class="form-group" style="margin-bottom: 0;">
              <label class="sf-label">URL Pattern</label>
              <input class="input" type="text" bind:value={formUrlPattern} placeholder="/{formSlug}/{'{slug}'}" style="font-family: var(--font-mono); font-size: 13px;" />
            </div>
            <div class="form-group" style="margin-bottom: 0;">
              <label class="sf-label" for="cs-sort">Sort</label>
              <div style="display: flex; gap: 6px;">
                <select id="cs-sort" class="input" bind:value={formSortField} style="flex: 1;">
                  <option value="created_at">Created At</option>
                  <option value="updated_at">Updated At</option>
                  <option value="published_at">Published At</option>
                  <option value="title">Title</option>
                  <option value="slug">Slug</option>
                  <option value="sort_order">Sort Order</option>
                </select>
                <select class="input" bind:value={formSortDirection} style="width: 60px; flex-shrink: 0; padding-right: 8px; font-size: 11px; text-align: center;">
                  <option value="DESC">DESC</option>
                  <option value="ASC">ASC</option>
                </select>
              </div>
            </div>
          </div>
        </div>

        <!-- Content Fields -->
        <div class="sf-fields-section">
          <div class="sf-fields-header">
            <span class="sf-section-title">Content fields</span>
            <span class="sf-field-count">{formSchema.length} of {MAX_FIELDS} used</span>
          </div>

          <div class="sf-field-list" bind:this={fieldListEl}>
            {#each formSchema as field, i (i)}
              <div
                class="sf-field-row"
                class:sf-field-row-selected={selectedFieldIndex === i}
                class:sf-field-row-expanded={expandedFields[i]}
              >
                <div class="sf-field-row-main" onclick={() => selectField(i)}>
                  <span class="sf-drag-handle" onclick={(e) => e.stopPropagation()} title="Drag to reorder">
                    <svg width="10" height="14" viewBox="0 0 10 14" fill="currentColor">
                      <circle cx="2.5" cy="2" r="1.2"/><circle cx="7.5" cy="2" r="1.2"/>
                      <circle cx="2.5" cy="7" r="1.2"/><circle cx="7.5" cy="7" r="1.2"/>
                      <circle cx="2.5" cy="12" r="1.2"/><circle cx="7.5" cy="12" r="1.2"/>
                    </svg>
                  </span>
                  <div class="sf-field-info">
                    <span class="sf-field-label">{field.label || 'Untitled field'}</span>
                    <span class="sf-field-name">{field.name || '---'}</span>
                  </div>
                  <span class="sf-type-badge" style="background: {typeBadgeColors[field.type]?.bg || 'var(--bg-tertiary)'}; color: {typeBadgeColors[field.type]?.color || 'var(--text-secondary)'};">
                    {typeLabels[field.type] || field.type}
                  </span>
                  <div class="sf-field-actions">
                    <button
                      class="sf-action-btn"
                      class:sf-action-btn-active={selectedFieldIndex === i || expandedFields[i]}
                      onclick={(e) => openFieldPanel(i, e)}
                      aria-label="Field settings"
                      title="Field settings"
                      type="button"
                    >
                      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83 0 2 2 0 010-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"/></svg>
                    </button>
                    <button
                      class="sf-action-btn sf-delete-btn"
                      onclick={(e) => { e.stopPropagation(); removeSchemaField(i); }}
                      aria-label="Remove field"
                      title="Remove field"
                      type="button"
                    >
                      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    </button>
                  </div>
                </div>

                <!-- Inline expanded settings for non-complex fields -->
                {#if expandedFields[i] && !isComplexField(field)}
                  <div class="sf-inline-settings">
                    <div class="sf-inline-grid-2">
                      <div class="form-group" style="margin-bottom: 0;">
                        <label class="sf-label">Field Label</label>
                        <input class="input" type="text" bind:value={field.label} oninput={() => autoFieldName(i)} placeholder="e.g. Hero Title" />
                      </div>
                      <div class="form-group" style="margin-bottom: 0;">
                        <label class="sf-label">Field Name</label>
                        <input class="input" type="text" bind:value={field.name} placeholder="auto_generated" style="font-family: var(--font-mono); font-size: 13px;" />
                      </div>
                    </div>
                    <div class="sf-inline-grid-2" style="margin-top: var(--space-sm);">
                      <div class="form-group" style="margin-bottom: 0;">
                        <label class="sf-label">Field Type</label>
                        <select class="input" bind:value={field.type}>
                          <option value="text">Text</option>
                          <option value="textarea">Textarea</option>
                          <option value="richtext">Rich Text</option>
                          <option value="image">Image</option>
                          <option value="date">Date</option>
                          <option value="number">Number</option>
                          <option value="toggle">Toggle</option>
                          <option value="select">Select</option>
                          <option value="color">Color</option>
                          <option value="link">Link</option>
                          <option value="repeater">Repeater</option>
                          <option value="gallery">Gallery</option>
                          <option value="folder">Folder</option>
                          <option value="relationship">Relationship</option>
                          <option value="flexible">Flexible Content</option>
                        </select>
                      </div>
                      <div class="form-group" style="margin-bottom: 0;">
                        <label class="sf-label">Required</label>
                        <label class="sf-toggle-row" style="padding-top: 6px;">
                          <input type="checkbox" bind:checked={field.required} style="display:none;" />
                          <button class="toggle" class:active={field.required} onclick={() => { field.required = !field.required; }} type="button"></button>
                        </label>
                      </div>
                    </div>
                    <div class="sf-inline-grid-2" style="margin-top: var(--space-sm);">
                      <div class="form-group" style="margin-bottom: 0;">
                        <label class="sf-label">Placeholder</label>
                        <input class="input" type="text" bind:value={field.placeholder} placeholder="Placeholder text..." />
                      </div>
                      <div class="form-group" style="margin-bottom: 0;">
                        <label class="sf-label">Default Value</label>
                        <input class="input" type="text" bind:value={field.defaultValue} placeholder="Default value" />
                      </div>
                    </div>
                    <div class="form-group" style="margin-bottom: 0; margin-top: var(--space-sm);">
                      <label class="sf-label">Instructions</label>
                      <input class="input" type="text" bind:value={field.description} placeholder="Help text displayed below the field" />
                    </div>

                    <!-- Type-specific: select choices -->
                    {#if field.type === 'select'}
                      <div class="form-group" style="margin-bottom: 0; margin-top: var(--space-sm);">
                        <label class="sf-label">Choices</label>
                        <textarea class="input" bind:value={field.choices} placeholder="One choice per line" rows="3" style="height: auto;"></textarea>
                        <span class="sf-help">Enter each choice on a new line</span>
                      </div>
                    {/if}

                    <!-- Conditional Logic -->
                    <div class="sf-conditions-row">
                      <div class="sf-conditions-toggle">
                        <span class="sf-label" style="margin-bottom: 0;">Conditional Logic</span>
                        <label style="display: inline-flex; align-items: center; gap: 4px; font-size: 12px; color: var(--text-secondary);">
                          <input type="checkbox" checked={field.conditions && field.conditions.length > 0} onchange={(e) => {
                            if (e.target.checked) {
                              field.conditions = [{ field: '', operator: '==', value: '' }];
                            } else {
                              field.conditions = [];
                            }
                          }} style="accent-color: var(--accent);" /> Enable
                        </label>
                      </div>
                      {#if field.conditions && field.conditions.length > 0}
                        {#each field.conditions as cond, ci}
                          <div class="sf-condition-row">
                            <select class="input" style="flex: 1; font-size: 12px; height: 30px;" bind:value={cond.field}>
                              <option value="">Select field...</option>
                              {#each formSchema.filter((f2, fi) => fi !== i) as otherField}
                                <option value={otherField.name || slugifyField(otherField.label)}>{otherField.label || otherField.name}</option>
                              {/each}
                            </select>
                            <select class="input" style="flex: 0 0 90px; font-size: 12px; height: 30px;" bind:value={cond.operator}>
                              <option value="==">equals</option>
                              <option value="!=">not equal</option>
                              <option value="not_empty">has value</option>
                              <option value="empty">is empty</option>
                            </select>
                            {#if cond.operator === '==' || cond.operator === '!='}
                              <input class="input" style="flex: 1; font-size: 12px; height: 30px;" type="text" bind:value={cond.value} placeholder="Value" />
                            {/if}
                            <button class="btn btn-ghost btn-sm" onclick={() => { field.conditions = field.conditions.filter((_, idx) => idx !== ci); }} type="button" style="padding: 4px;">
                              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                            </button>
                          </div>
                        {/each}
                        <button class="sf-text-btn" onclick={() => { field.conditions = [...field.conditions, { field: '', operator: '==', value: '' }]; }} type="button">
                          + Add condition
                        </button>
                      {/if}
                    </div>
                  </div>
                {/if}
              </div>
            {/each}
          </div>

          <!-- Add field button -->
          <button class="sf-add-field-btn" onclick={addSchemaField} type="button" disabled={formSchema.length >= MAX_FIELDS}>
            + Add Field
          </button>
        </div>

        <!-- Advanced Settings (collapsed) -->
        {#if advancedOpen}
          <div class="card sf-advanced-card">
            <div class="sf-advanced-header">
              <span class="sf-section-title" style="font-size: 13px;">Advanced</span>
            </div>

            <div class="sf-advanced-body">
              <div class="sf-advanced-row">
                <label class="sf-toggle-row" style="cursor: pointer;">
                  <input type="checkbox" bind:checked={formRequireReview} style="display:none;" />
                  <button class="toggle" class:active={formRequireReview} onclick={() => { formRequireReview = !formRequireReview; }} type="button"></button>
                  <span style="font-size: var(--font-size-sm); color: var(--text-secondary);">Require review before publishing</span>
                </label>
                <span class="sf-help">Editors must submit for review; admins approve or reject.</span>
              </div>

              {#if availableWorkflows.length > 0}
                <div class="sf-advanced-row">
                  <label class="sf-label">Workflow</label>
                  <select class="input" value={formWorkflowId || ''} onchange={(e) => { formWorkflowId = e.target.value ? Number(e.target.value) : null; }}>
                    <option value="">Default (Simple)</option>
                    {#each availableWorkflows as wf}
                      <option value={wf.id}>{wf.name} ({wf.stages.length} stages)</option>
                    {/each}
                  </select>
                  <span class="sf-help">
                    Assign a workflow to define custom approval stages.
                    <a href="#" onclick={(e) => { e.preventDefault(); navigate('workflows'); }} style="color: var(--accent);">Manage workflows</a>
                  </span>
                </div>
              {/if}

              <!-- Lodge -->
              <div class="sf-lodge-card">
                <div class="sf-lodge-header">
                  <span class="sf-section-label">LODGE</span>
                  <button class="toggle" class:active={formLodgeEnabled} onclick={() => { formLodgeEnabled = !formLodgeEnabled; }} type="button"></button>
                </div>
                <span class="sf-help" style="display: block; margin-bottom: var(--space-sm);">Allow members to create and manage their own content in this collection.</span>

                {#if formLodgeEnabled}
                  <div class="sf-lodge-options">
                    <div class="sf-lodge-toggle-row">
                      <label class="sf-toggle-row" style="cursor: pointer;">
                        <input type="checkbox" bind:checked={formLodgeConfig.allow_create} style="accent-color: var(--accent);" />
                        <span style="font-size: var(--font-size-sm); color: var(--text-secondary);">Allow Create</span>
                      </label>
                      <span class="sf-help" style="padding-left: 26px;">Members can create new items</span>
                    </div>
                    <div class="sf-lodge-toggle-row">
                      <label class="sf-toggle-row" style="cursor: pointer;">
                        <input type="checkbox" bind:checked={formLodgeConfig.allow_edit} style="accent-color: var(--accent);" />
                        <span style="font-size: var(--font-size-sm); color: var(--text-secondary);">Allow Edit</span>
                      </label>
                      <span class="sf-help" style="padding-left: 26px;">Members can edit their own items</span>
                    </div>
                    <div class="sf-lodge-toggle-row">
                      <label class="sf-toggle-row" style="cursor: pointer;">
                        <input type="checkbox" bind:checked={formLodgeConfig.allow_delete} style="accent-color: var(--accent);" />
                        <span style="font-size: var(--font-size-sm); color: var(--text-secondary);">Allow Delete</span>
                      </label>
                      <span class="sf-help" style="padding-left: 26px;">Members can delete their own items</span>
                    </div>
                    <div class="sf-lodge-toggle-row">
                      <label class="sf-toggle-row" style="cursor: pointer;">
                        <input type="checkbox" bind:checked={formLodgeConfig.require_approval} style="accent-color: var(--accent);" />
                        <span style="font-size: var(--font-size-sm); color: var(--text-secondary);">Require Approval</span>
                      </label>
                      <span class="sf-help" style="padding-left: 26px;">Submissions require admin approval before publishing</span>
                    </div>
                    <div style="display: flex; flex-direction: column; gap: 4px; margin-top: var(--space-xs);">
                      <label class="sf-label">Max Items Per Member</label>
                      <div style="display: flex; align-items: center; gap: var(--space-sm);">
                        <input class="input" type="number" min="0" style="width: 100px; height: 30px; font-size: 13px;" bind:value={formLodgeConfig.max_items_per_member} />
                        <span class="sf-help">0 = unlimited</span>
                      </div>
                    </div>

                    {#if formSchema.filter(f => f.name || f.label).length > 0}
                      {@const schemaFields = formSchema.filter(f => f.name || f.label).map(f => f.name || slugifyField(f.label))}
                      <div style="display: flex; flex-direction: column; gap: 4px; margin-top: var(--space-xs);">
                        <label class="sf-label">Editable Fields</label>
                        <div class="sf-lodge-checks">
                          {#each schemaFields as fieldName}
                            <label class="sf-lodge-check">
                              <input type="checkbox"
                                checked={formLodgeConfig.editable_fields.includes(fieldName)}
                                onchange={(e) => {
                                  if (e.target.checked) {
                                    formLodgeConfig.editable_fields = [...formLodgeConfig.editable_fields, fieldName];
                                    formLodgeConfig.readonly_fields = formLodgeConfig.readonly_fields.filter(f => f !== fieldName);
                                  } else {
                                    formLodgeConfig.editable_fields = formLodgeConfig.editable_fields.filter(f => f !== fieldName);
                                  }
                                }}
                                style="accent-color: var(--accent);"
                              />
                              {fieldName}
                            </label>
                          {/each}
                        </div>
                        <span class="sf-help">Fields members can edit. Leave empty to allow all fields.</span>
                      </div>
                      <div style="display: flex; flex-direction: column; gap: 4px; margin-top: var(--space-xs);">
                        <label class="sf-label">Read-only Fields</label>
                        <div class="sf-lodge-checks">
                          {#each schemaFields as fieldName}
                            <label class="sf-lodge-check">
                              <input type="checkbox"
                                checked={formLodgeConfig.readonly_fields.includes(fieldName)}
                                onchange={(e) => {
                                  if (e.target.checked) {
                                    formLodgeConfig.readonly_fields = [...formLodgeConfig.readonly_fields, fieldName];
                                    formLodgeConfig.editable_fields = formLodgeConfig.editable_fields.filter(f => f !== fieldName);
                                  } else {
                                    formLodgeConfig.readonly_fields = formLodgeConfig.readonly_fields.filter(f => f !== fieldName);
                                  }
                                }}
                                style="accent-color: var(--accent);"
                              />
                              {fieldName}
                            </label>
                          {/each}
                        </div>
                        <span class="sf-help">Fields visible to members but not editable.</span>
                      </div>
                    {/if}
                  </div>
                {/if}
              </div>
            </div>
          </div>
        {/if}
      </div>

      <!-- RIGHT PANEL — shows when a complex field is selected -->
      {#if selectedFieldIndex !== null && selectedField}
        <div class="sf-panel">
          <div class="sf-panel-header">
            <div class="sf-panel-title-row">
              <div>
                <span class="sf-panel-title">{selectedField.label || 'Untitled field'}</span>
                <span class="sf-panel-subtitle">{typeLabels[selectedField.type] || selectedField.type} — configure sub-fields</span>
              </div>
              <button class="sf-panel-close" onclick={() => { selectedFieldIndex = null; }} type="button" aria-label="Close panel">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
              </button>
            </div>
          </div>

          <div class="sf-panel-body">
            <!-- Core field settings -->
            <div class="form-group" style="margin-bottom: var(--space-md);">
              <label class="sf-label">Field Label</label>
              <input class="input" type="text" bind:value={selectedField.label} oninput={() => autoFieldName(selectedFieldIndex)} placeholder="e.g. Business Hours" />
            </div>
            <div class="sf-inline-grid-2" style="margin-bottom: var(--space-md);">
              <div class="form-group" style="margin-bottom: 0;">
                <label class="sf-label">Field Name</label>
                <input class="input" type="text" bind:value={selectedField.name} placeholder="auto_generated" style="font-family: var(--font-mono); font-size: 13px;" />
              </div>
              <div class="form-group" style="margin-bottom: 0;">
                <label class="sf-label">Required</label>
                <label class="sf-toggle-row" style="padding-top: 6px;">
                  <input type="checkbox" bind:checked={selectedField.required} style="display:none;" />
                  <button class="toggle" class:active={selectedField.required} onclick={() => { selectedField.required = !selectedField.required; }} type="button"></button>
                </label>
              </div>
            </div>

            <!-- Relationship-specific settings -->
            {#if selectedField.type === 'relationship'}
              <div class="sf-panel-type-settings">
                <div class="form-group" style="margin-bottom: var(--space-sm);">
                  <label class="sf-label">Collection</label>
                  <select class="input" bind:value={selectedField.relCollection}>
                    <option value="">Select collection...</option>
                    {#each allCollections.filter(c => c.slug !== formSlug) as c}
                      <option value={c.slug}>{c.name}</option>
                    {/each}
                  </select>
                </div>
                <div class="sf-inline-grid-2">
                  <div class="form-group" style="margin-bottom: 0;">
                    <label class="sf-label">Allow Multiple</label>
                    <label class="sf-toggle-row" style="padding-top: 4px;">
                      <button class="toggle" class:active={selectedField.relMultiple} onclick={() => { selectedField.relMultiple = !selectedField.relMultiple; }} type="button"></button>
                    </label>
                  </div>
                  <div class="form-group" style="margin-bottom: 0;">
                    <label class="sf-label">Max Items</label>
                    <input class="input" type="number" bind:value={selectedField.relMax} placeholder="0 = unlimited" min="0" />
                  </div>
                </div>
              </div>
            {/if}

            <!-- Repeater sub-fields -->
            {#if selectedField.type === 'repeater'}
              <div class="sf-panel-type-settings">
                <div class="sf-repeater-toolbar">
                  <span class="sf-label" style="margin-bottom: 0;">Sub-fields</span>
                  <button
                    class="sf-pill-toggle"
                    onclick={() => { repeaterJsonMode = { ...repeaterJsonMode, [selectedFieldIndex]: !repeaterJsonMode[selectedFieldIndex] }; if (!repeaterJsonMode[selectedFieldIndex]) syncRepeaterJsonToVisual(selectedFieldIndex); }}
                    type="button"
                  >
                    <span class="sf-pill-opt" class:selected={!repeaterJsonMode[selectedFieldIndex]}>Visual</span>
                    <span class="sf-pill-opt" class:selected={repeaterJsonMode[selectedFieldIndex]}>JSON</span>
                  </button>
                </div>

                {#if repeaterJsonMode[selectedFieldIndex]}
                  <textarea
                    class="input"
                    bind:value={selectedField.repeaterFields}
                    placeholder={'[{"name": "day", "type": "select", "label": "Day", "options": ["Mon","Tue"]}]'}
                    rows="8"
                    style="height: auto; font-family: var(--font-mono); font-size: 12px;"
                  ></textarea>
                  <span class="sf-help">JSON array. Each entry: name, type, label. For select: add options array.</span>
                {:else}
                  {@const visual = getRepeaterVisual(selectedField)}
                  {#if visual.length === 0 && (!selectedField.repeaterVisual || selectedField.repeaterVisual.length === 0)}
                    <p class="sf-help" style="padding: 12px 0;">No sub-fields defined yet.</p>
                  {:else}
                    <div class="sf-subfield-list">
                      {#each (selectedField.repeaterVisual && selectedField.repeaterVisual.length > 0 ? selectedField.repeaterVisual : visual) as sub, si}
                        <div class="sf-subfield-row">
                          <div class="sf-subfield-info">
                            <input
                              class="input"
                              type="text"
                              bind:value={sub.label}
                              oninput={() => autoSubFieldName(selectedFieldIndex, si)}
                              placeholder="Sub-field label"
                              style="height: 30px; font-size: 13px; font-weight: 500; border: none; padding: 0; background: transparent;"
                            />
                            <span class="sf-subfield-name">{sub.name || '---'}</span>
                          </div>
                          <select class="input" bind:value={sub.type} onchange={() => syncRepeaterVisualToJson(selectedFieldIndex)} style="width: 90px; height: 28px; font-size: 11px; flex-shrink: 0;">
                            <option value="text">Text</option>
                            <option value="textarea">Textarea</option>
                            <option value="select">Select</option>
                            <option value="toggle">Toggle</option>
                            <option value="number">Number</option>
                            <option value="date">Date</option>
                            <option value="image">Image</option>
                          </select>
                          <span class="sf-type-badge" style="background: {typeBadgeColors[sub.type]?.bg || 'var(--bg-tertiary)'}; color: {typeBadgeColors[sub.type]?.color || 'var(--text-secondary)'}; font-size: 10px; padding: 1px 6px;">
                            {typeLabels[sub.type] || sub.type}
                          </span>
                          <button class="sf-action-btn sf-delete-btn" onclick={() => removeRepeaterSubField(selectedFieldIndex, si)} aria-label="Remove sub-field" type="button" style="opacity: 1;">
                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                          </button>
                          {#if sub.type === 'select'}
                            <div class="sf-subfield-options">
                              <input
                                class="input"
                                type="text"
                                value={Array.isArray(sub.options) ? sub.options.join(', ') : (sub.options || '')}
                                oninput={(e) => {
                                  sub.options = e.target.value.split(',').map(o => o.trim()).filter(Boolean);
                                  syncRepeaterVisualToJson(selectedFieldIndex);
                                }}
                                placeholder="Option 1, Option 2, Option 3"
                                style="height: 28px; font-size: 12px;"
                              />
                              <span class="sf-help">Comma-separated options</span>
                            </div>
                          {/if}
                        </div>
                      {/each}
                    </div>
                  {/if}
                  <button class="sf-add-field-btn" onclick={() => addRepeaterSubField(selectedFieldIndex)} type="button" style="margin-top: var(--space-sm);">
                    + Add Sub-field
                  </button>
                  <span class="sf-help" style="display: block; margin-top: var(--space-sm);">Sub-fields define the columns for each repeated row. Drag to reorder.</span>
                {/if}
              </div>
            {/if}

            <!-- Flexible layouts -->
            {#if selectedField.type === 'flexible'}
              <div class="sf-panel-type-settings">
                <label class="sf-label">Layouts (JSON)</label>
                <textarea class="input" bind:value={selectedField.flexLayouts} placeholder="Paste layout JSON here..." rows="8" style="height: auto; font-family: var(--font-mono); font-size: 12px;"></textarea>
                <span class="sf-help">Define layout types with named sub-fields.</span>
              </div>
            {/if}

            <!-- Conditional Logic -->
            <div class="sf-conditions-section">
              <div class="sf-conditions-toggle">
                <span class="sf-label" style="margin-bottom: 0;">Conditional Logic</span>
                <label style="display: inline-flex; align-items: center; gap: 4px; font-size: 12px; color: var(--text-secondary);">
                  <input type="checkbox" checked={selectedField.conditions && selectedField.conditions.length > 0} onchange={(e) => {
                    if (e.target.checked) {
                      selectedField.conditions = [{ field: '', operator: '==', value: '' }];
                    } else {
                      selectedField.conditions = [];
                    }
                  }} style="accent-color: var(--accent);" /> Enable
                </label>
              </div>
              {#if selectedField.conditions && selectedField.conditions.length > 0}
                {#each selectedField.conditions as cond, ci}
                  <div class="sf-condition-row">
                    <select class="input" style="flex: 1; font-size: 12px; height: 30px;" bind:value={cond.field}>
                      <option value="">Select field...</option>
                      {#each formSchema.filter((f2, fi) => fi !== selectedFieldIndex) as otherField}
                        <option value={otherField.name || slugifyField(otherField.label)}>{otherField.label || otherField.name}</option>
                      {/each}
                    </select>
                    <select class="input" style="flex: 0 0 90px; font-size: 12px; height: 30px;" bind:value={cond.operator}>
                      <option value="==">equals</option>
                      <option value="!=">not equal</option>
                      <option value="not_empty">has value</option>
                      <option value="empty">is empty</option>
                    </select>
                    {#if cond.operator === '==' || cond.operator === '!='}
                      <input class="input" style="flex: 1; font-size: 12px; height: 30px;" type="text" bind:value={cond.value} placeholder="Value" />
                    {/if}
                    <button class="btn btn-ghost btn-sm" onclick={() => { selectedField.conditions = selectedField.conditions.filter((_, idx) => idx !== ci); }} type="button" style="padding: 4px;">
                      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    </button>
                  </div>
                {/each}
                <button class="sf-text-btn" onclick={() => { selectedField.conditions = [...selectedField.conditions, { field: '', operator: '==', value: '' }]; }} type="button">
                  + Add condition
                </button>
              {/if}
            </div>
          </div>
        </div>
      {/if}
    </div>
  </div>
{/if}

<style>
  /* ── Breadcrumb back link ──────────────────────── */
  .sf-back-link {
    background: none;
    border: none;
    color: var(--accent);
    font-size: inherit;
    cursor: pointer;
    padding: 0;
  }
  .sf-back-link:hover { text-decoration: underline; }

  /* ── Shared labels ─────────────────────────────── */
  .sf-label {
    display: block;
    font-size: 11px;
    font-weight: 600;
    color: var(--text-tertiary);
    text-transform: uppercase;
    letter-spacing: 0.04em;
    margin-bottom: 4px;
  }

  .sf-section-label {
    font-size: 11px;
    font-weight: 600;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--text-tertiary);
  }

  .sf-section-title {
    font-size: 15px;
    font-weight: 600;
    color: var(--text-primary);
  }

  .sf-help {
    font-size: 11px;
    color: var(--text-tertiary);
    margin-top: 2px;
  }

  .sf-toggle-row {
    display: flex;
    align-items: center;
    gap: 8px;
  }

  .sf-text-btn {
    background: none;
    border: none;
    color: var(--accent);
    font-size: 12px;
    font-weight: 500;
    cursor: pointer;
    padding: var(--space-xs) 0;
  }
  .sf-text-btn:hover { text-decoration: underline; }

  /* ── Overflow menu ─────────────────────────────── */
  .sf-overflow-wrap {
    position: relative;
  }

  .sf-overflow-menu {
    position: absolute;
    right: 0;
    top: 100%;
    margin-top: 4px;
    background: var(--bg-card);
    border: 1px solid var(--border-primary);
    border-radius: var(--radius-md);
    box-shadow: var(--shadow-md);
    min-width: 200px;
    z-index: 50;
    padding: 4px;
  }

  .sf-overflow-item {
    display: block;
    width: 100%;
    text-align: left;
    padding: 8px 12px;
    background: none;
    border: none;
    border-radius: var(--radius-sm);
    font-size: 13px;
    color: var(--text-secondary);
    cursor: pointer;
  }
  .sf-overflow-item:hover {
    background: var(--bg-hover);
    color: var(--text-primary);
  }

  /* ── Two-panel layout ──────────────────────────── */
  .sf-layout {
    display: grid;
    grid-template-columns: 1fr;
    gap: var(--space-xl);
  }

  .sf-layout-with-panel {
    grid-template-columns: 1fr 380px;
  }

  .sf-main {
    min-width: 0;
  }

  /* ── Settings card ─────────────────────────────── */
  .sf-settings-card {
    margin-bottom: var(--space-xl);
    padding: var(--space-lg) var(--space-xl);
  }

  .sf-settings-grid-3 {
    display: grid;
    grid-template-columns: 1fr 1fr 90px;
    gap: var(--space-md);
  }

  .sf-slug-row {
    padding: 8px 0;
  }

  .sf-slug-badge {
    font-size: 12px;
    font-family: var(--font-mono);
    color: var(--text-secondary);
    background: var(--bg-tertiary);
    padding: 2px 8px;
    border-radius: 4px;
  }

  .sf-slug-hint {
    margin-left: 6px;
    font-size: var(--font-size-xs);
    color: var(--text-tertiary);
  }

  /* ── Fields section ────────────────────────────── */
  .sf-fields-section {
    margin-bottom: var(--space-xl);
  }

  .sf-fields-header {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    margin-bottom: var(--space-md);
  }

  .sf-field-count {
    font-size: var(--font-size-xs);
    color: var(--text-tertiary);
  }

  /* ── Field list ────────────────────────────────── */
  .sf-field-list {
    border: 1px solid var(--border-primary);
    border-radius: var(--radius-lg);
    overflow: hidden;
    background: var(--bg-card);
  }

  .sf-sortable-ghost {
    opacity: 0.35;
  }

  .sf-field-row {
    border-bottom: 1px solid var(--border-secondary);
  }

  .sf-field-row:last-child {
    border-bottom: none;
  }

  .sf-field-row-selected {
    background: var(--accent-soft);
    border-left: 3px solid var(--accent);
  }

  .sf-field-row-expanded {
    background: var(--bg-secondary);
  }

  /* ── Row main (clickable strip) ────────────────── */
  .sf-field-row-main {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
    padding: var(--space-md) var(--space-lg);
    cursor: pointer;
    transition: background var(--transition-fast);
    user-select: none;
  }

  .sf-field-row-main:hover {
    background: var(--bg-hover);
  }

  .sf-field-row-selected .sf-field-row-main:hover {
    background: rgba(45, 90, 71, 0.06);
  }

  .sf-drag-handle {
    cursor: grab;
    color: var(--text-light);
    display: flex;
    align-items: center;
    padding: 4px 2px;
    opacity: 0;
    transition: opacity 0.15s;
    flex-shrink: 0;
  }

  .sf-field-row:hover .sf-drag-handle {
    opacity: 0.5;
  }

  .sf-drag-handle:hover {
    opacity: 1 !important;
  }

  .sf-field-info {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 1px;
  }

  .sf-field-label {
    font-size: 15px;
    font-weight: 500;
    color: var(--text-primary);
    line-height: 1.3;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .sf-field-name {
    font-size: 11px;
    font-family: var(--font-mono);
    color: var(--text-tertiary);
    line-height: 1.2;
  }

  .sf-type-badge {
    font-size: 11px;
    font-weight: 600;
    padding: 2px 8px;
    border-radius: 4px;
    flex-shrink: 0;
    white-space: nowrap;
  }

  .sf-field-actions {
    display: flex;
    align-items: center;
    gap: 2px;
    flex-shrink: 0;
    opacity: 0;
    transition: opacity 0.15s;
  }

  .sf-field-row:hover .sf-field-actions {
    opacity: 1;
  }

  .sf-action-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    border: none;
    background: none;
    color: var(--text-tertiary);
    cursor: pointer;
    border-radius: var(--radius-sm);
    transition: all 0.1s;
  }

  .sf-action-btn:hover {
    background: var(--bg-hover);
    color: var(--text-primary);
  }

  .sf-action-btn-active {
    color: var(--accent);
  }

  .sf-delete-btn:hover {
    background: var(--danger-soft);
    color: var(--danger);
  }

  /* ── Inline expanded settings ──────────────────── */
  .sf-inline-settings {
    padding: var(--space-md) var(--space-xl);
    padding-left: calc(var(--space-xl) + 18px);
    border-top: 1px solid var(--border-secondary);
    background: var(--bg-primary);
  }

  .sf-inline-grid-2 {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: var(--space-md);
  }

  /* ── Add field button ──────────────────────────── */
  .sf-add-field-btn {
    width: 100%;
    padding: var(--space-md);
    margin-top: var(--space-sm);
    border: 1px dashed var(--border-primary);
    border-radius: var(--radius-md);
    background: none;
    color: var(--text-tertiary);
    font-size: 14px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.15s;
  }

  .sf-add-field-btn:hover:not(:disabled) {
    border-color: var(--accent);
    color: var(--accent);
    background: var(--accent-soft);
  }

  .sf-add-field-btn:disabled {
    opacity: 0.4;
    cursor: not-allowed;
  }

  /* ── Conditions ────────────────────────────────── */
  .sf-conditions-section {
    margin-top: var(--space-lg);
    padding-top: var(--space-md);
    border-top: 1px solid var(--border-secondary);
  }

  .sf-conditions-toggle {
    display: flex;
    align-items: center;
    gap: var(--space-md);
    margin-bottom: var(--space-sm);
  }

  .sf-conditions-row {
    margin-top: var(--space-md);
    padding-top: var(--space-sm);
    border-top: 1px solid var(--border-secondary);
  }

  .sf-condition-row {
    display: flex;
    gap: 6px;
    align-items: center;
    margin-top: 4px;
  }

  /* ── RIGHT PANEL ───────────────────────────────── */
  .sf-panel {
    background: var(--bg-card);
    border: 1px solid var(--border-primary);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-sm);
    position: sticky;
    top: var(--space-xl);
    max-height: calc(100vh - 120px);
    overflow-y: auto;
  }

  .sf-panel-header {
    padding: var(--space-lg) var(--space-xl);
    border-bottom: 1px solid var(--border-secondary);
  }

  .sf-panel-title-row {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: var(--space-sm);
  }

  .sf-panel-title {
    font-size: 16px;
    font-weight: 600;
    color: var(--text-primary);
    display: block;
    line-height: 1.3;
  }

  .sf-panel-subtitle {
    font-size: 12px;
    color: var(--text-tertiary);
    display: block;
    margin-top: 2px;
  }

  .sf-panel-close {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    border: none;
    background: none;
    color: var(--text-tertiary);
    cursor: pointer;
    border-radius: var(--radius-sm);
    flex-shrink: 0;
  }
  .sf-panel-close:hover {
    background: var(--bg-hover);
    color: var(--text-primary);
  }

  .sf-panel-body {
    padding: var(--space-lg) var(--space-xl);
  }

  .sf-panel-type-settings {
    margin-top: var(--space-lg);
    padding-top: var(--space-md);
    border-top: 1px solid var(--border-secondary);
  }

  /* ── Repeater toolbar ──────────────────────────── */
  .sf-repeater-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: var(--space-sm);
  }

  .sf-pill-toggle {
    display: inline-flex;
    background: var(--bg-hover);
    border: none;
    border-radius: 6px;
    padding: 2px;
    cursor: pointer;
    gap: 0;
  }

  .sf-pill-opt {
    font-size: 11px;
    font-weight: 500;
    padding: 3px 10px;
    border-radius: 4px;
    color: var(--text-tertiary);
    transition: all 0.15s;
  }

  .sf-pill-opt.selected {
    background: var(--bg-primary);
    color: var(--text-primary);
    box-shadow: var(--shadow-sm);
  }

  /* ── Sub-field list in panel ───────────────────── */
  .sf-subfield-list {
    display: flex;
    flex-direction: column;
    border: 1px solid var(--border-secondary);
    border-radius: var(--radius-sm);
    overflow: hidden;
    border-left: 3px solid var(--accent);
  }

  .sf-subfield-row {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
    padding: var(--space-sm) var(--space-md);
    border-bottom: 1px solid var(--border-secondary);
    flex-wrap: wrap;
  }

  .sf-subfield-row:last-child {
    border-bottom: none;
  }

  .sf-subfield-info {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 0;
  }

  .sf-subfield-name {
    font-size: 10px;
    font-family: var(--font-mono);
    color: var(--text-tertiary);
  }

  .sf-subfield-options {
    width: 100%;
    display: flex;
    flex-direction: column;
    gap: 2px;
    padding-top: 4px;
  }

  /* ── Advanced card ─────────────────────────────── */
  .sf-advanced-card {
    margin-bottom: var(--space-xl);
  }

  .sf-advanced-header {
    margin-bottom: var(--space-md);
    padding-bottom: var(--space-sm);
    border-bottom: 1px solid var(--border-secondary);
  }

  .sf-advanced-body {
    display: flex;
    flex-direction: column;
    gap: var(--space-lg);
  }

  .sf-advanced-row {
    display: flex;
    flex-direction: column;
    gap: 4px;
  }

  /* ── Lodge section ─────────────────────────────── */
  .sf-lodge-card {
    padding: var(--space-md);
    border: 1px solid var(--border-primary);
    border-radius: var(--radius-md);
    margin-top: var(--space-sm);
  }

  .sf-lodge-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: var(--space-xs);
  }

  .sf-lodge-options {
    display: flex;
    flex-direction: column;
    gap: var(--space-sm);
    padding-top: var(--space-sm);
    border-top: 1px solid var(--border-secondary);
  }

  .sf-lodge-toggle-row {
    display: flex;
    flex-direction: column;
    gap: 2px;
  }

  .sf-lodge-checks {
    display: flex;
    flex-wrap: wrap;
    gap: 4px 14px;
  }

  .sf-lodge-check {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: var(--font-size-sm);
    color: var(--text-secondary);
    cursor: pointer;
  }

  /* ── Responsive ────────────────────────────────── */
  @media (max-width: 1024px) {
    .sf-layout-with-panel {
      grid-template-columns: 1fr;
    }

    .sf-panel {
      position: static;
      max-height: none;
    }
  }

  @media (max-width: 768px) {
    .sf-settings-grid-3 {
      grid-template-columns: 1fr;
    }

    .sf-inline-grid-2 {
      grid-template-columns: 1fr;
    }

    .sf-field-row-main {
      flex-wrap: wrap;
      gap: var(--space-xs);
    }

    .sf-field-name {
      display: none;
    }

    .sf-inline-settings {
      padding-left: var(--space-lg);
    }
  }
</style>
