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
  let expandedFields = $state({});

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

  // Field type display labels & colors
  const fieldTypeMeta = {
    text: { label: 'Text', color: 'var(--text-tertiary)' },
    textarea: { label: 'Textarea', color: 'var(--text-tertiary)' },
    richtext: { label: 'Rich Text', color: 'var(--accent)' },
    image: { label: 'Image', color: 'var(--clay)' },
    date: { label: 'Date', color: 'var(--warning)' },
    number: { label: 'Number', color: 'var(--sage)' },
    toggle: { label: 'Toggle', color: 'var(--success)' },
    select: { label: 'Select', color: 'var(--warning)' },
    color: { label: 'Color', color: 'var(--clay)' },
    link: { label: 'Link', color: 'var(--accent)' },
    repeater: { label: 'Repeater', color: 'var(--accent)' },
    gallery: { label: 'Gallery', color: 'var(--clay)' },
    folder: { label: 'Folder', color: 'var(--sage)' },
    relationship: { label: 'Relationship', color: 'var(--accent)' },
    flexible: { label: 'Flexible', color: 'var(--warning)' },
  };

  function toggleFieldExpand(i) {
    expandedFields = { ...expandedFields, [i]: !expandedFields[i] };
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
  }

  function removeSchemaField(i) {
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
    // Init sortable after DOM renders
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
      handle: '.cs-drag-handle',
      ghostClass: 'cs-sortable-ghost',
      onEnd(evt) {
        if (evt.oldIndex !== evt.newIndex) {
          const arr = [...formSchema];
          const [moved] = arr.splice(evt.oldIndex, 1);
          arr.splice(evt.newIndex, 0, moved);
          formSchema = arr;
        }
      },
    });
  }

  $effect(() => {
    // Re-init sortable when field count changes
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
      // Refresh collections list
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
</script>

<svelte:window onkeydown={handleKeyboardSave} />

{#if loading}
  <div class="loading-overlay"><div class="spinner"></div></div>
{:else if collection}
  <div class="cs-page">
    <!-- Top bar -->
    <header class="cs-topbar">
      <div class="cs-topbar-left">
        <button class="cs-back" onclick={goBack} type="button">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
          <span>Collections</span>
        </button>
      </div>
      <div class="cs-topbar-right">
        <button class="btn btn-primary" onclick={saveCollection} disabled={saving || !formName}>
          {saving ? 'Saving...' : 'Save Changes'}
        </button>
      </div>
    </header>

    <div class="cs-body">
      <div class="cs-content">
        <!-- Page title -->
        <h1 class="cs-title">{formName || 'Collection Schema'}</h1>

        <!-- Section: Collection Settings -->
        <section class="cs-section">
          <div class="cs-section-header">
            <h2 class="cs-section-label">Collection Settings</h2>
          </div>

          <div class="cs-settings-grid">
            <div class="cs-field">
              <label class="cs-label" for="cs-name">Name</label>
              <input id="cs-name" class="input" type="text" bind:value={formName} placeholder="Blog Posts" />
            </div>
            <div class="cs-field">
              <label class="cs-label" for="cs-singular">Singular Name</label>
              <input id="cs-singular" class="input" type="text" bind:value={formSingularName} placeholder="Blog Post" />
            </div>
          </div>

          <div class="cs-settings-grid">
            <div class="cs-field">
              <label class="cs-label">Slug</label>
              <div class="cs-slug-display">
                <code>{formSlug}</code>
                <span class="cs-hint">Cannot be changed</span>
              </div>
            </div>
            <div class="cs-field">
              <label class="cs-label" for="cs-url">URL Pattern</label>
              <input id="cs-url" class="input cs-mono-input" type="text" bind:value={formUrlPattern} placeholder="/{formSlug}/{'{slug}'}" />
            </div>
          </div>

          <div class="cs-settings-grid cs-settings-grid-three">
            <div class="cs-field">
              <label class="cs-label" for="cs-sort-field">Sort Field</label>
              <select id="cs-sort-field" class="input" bind:value={formSortField}>
                <option value="created_at">Created At</option>
                <option value="updated_at">Updated At</option>
                <option value="published_at">Published At</option>
                <option value="slug">Slug</option>
                <option value="sort_order">Sort Order</option>
              </select>
            </div>
            <div class="cs-field">
              <label class="cs-label" for="cs-sort-dir">Sort Direction</label>
              <select id="cs-sort-dir" class="input" bind:value={formSortDirection}>
                <option value="DESC">Descending</option>
                <option value="ASC">Ascending</option>
              </select>
            </div>
            <div class="cs-field">
              <label class="cs-label" for="cs-per-page">Per Page</label>
              <input id="cs-per-page" class="input" type="number" min="1" max="100" bind:value={formItemsPerPage} />
            </div>
          </div>
        </section>

        <!-- Section: Schema Fields -->
        <section class="cs-section">
          <div class="cs-section-header">
            <h2 class="cs-section-label">Schema Fields</h2>
            <span class="cs-field-count">{formSchema.length} {formSchema.length === 1 ? 'field' : 'fields'}</span>
          </div>

          <div class="cs-field-list" bind:this={fieldListEl}>
            {#each formSchema as field, i (i)}
              <div class="cs-schema-card" class:cs-schema-card-expanded={expandedFields[i]}>
                <div class="cs-schema-header">
                  <span class="cs-drag-handle" title="Drag to reorder">
                    <svg width="10" height="14" viewBox="0 0 10 14" fill="currentColor">
                      <circle cx="2.5" cy="2" r="1.2"/><circle cx="7.5" cy="2" r="1.2"/>
                      <circle cx="2.5" cy="7" r="1.2"/><circle cx="7.5" cy="7" r="1.2"/>
                      <circle cx="2.5" cy="12" r="1.2"/><circle cx="7.5" cy="12" r="1.2"/>
                    </svg>
                  </span>

                  <div class="cs-schema-main">
                    <input class="cs-schema-label-input" type="text" bind:value={field.label} oninput={() => autoFieldName(i)} placeholder="Field label" />
                  </div>

                  <div class="cs-type-pill" style="--pill-color: {fieldTypeMeta[field.type]?.color || 'var(--text-tertiary)'}">
                    <select class="cs-type-select" bind:value={field.type}>
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
                      <option value="flexible">Flexible</option>
                    </select>
                  </div>

                  <div class="cs-schema-actions">
                    <label class="cs-required-toggle" title="Required">
                      <input type="checkbox" bind:checked={field.required} style="display:none;" />
                      <span class="cs-required-star" class:active={field.required}>*</span>
                    </label>
                    <button class="cs-action-btn" class:cs-action-btn-active={expandedFields[i]} onclick={() => toggleFieldExpand(i)} aria-label="Field options" title="Field options" type="button">
                      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83 0 2 2 0 010-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"/></svg>
                    </button>
                    <button class="cs-action-btn cs-action-btn-danger" onclick={() => removeSchemaField(i)} aria-label="Remove field" type="button">
                      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    </button>
                  </div>
                </div>

                <div class="cs-schema-meta">
                  <span class="cs-machine-name">{field.name || '---'}</span>
                </div>

                {#if expandedFields[i]}
                  <div class="cs-schema-options">
                    <div class="cs-opt-grid">
                      <div class="cs-opt-row">
                        <label class="cs-opt-label">Machine Name</label>
                        <input class="input cs-opt-input" type="text" bind:value={field.name} placeholder="auto_generated" />
                      </div>
                      <div class="cs-opt-row">
                        <label class="cs-opt-label">Placeholder</label>
                        <input class="input cs-opt-input" type="text" bind:value={field.placeholder} placeholder="Placeholder text..." />
                      </div>
                      <div class="cs-opt-row">
                        <label class="cs-opt-label">Description</label>
                        <input class="input cs-opt-input" type="text" bind:value={field.description} placeholder="Help text shown below field" />
                      </div>
                      <div class="cs-opt-row">
                        <label class="cs-opt-label">Default Value</label>
                        <input class="input cs-opt-input" type="text" bind:value={field.defaultValue} placeholder="Default value" />
                      </div>
                    </div>

                    {#if field.type === 'select'}
                      <div class="cs-opt-row cs-opt-row-full">
                        <label class="cs-opt-label">Choices</label>
                        <textarea class="input cs-opt-input cs-opt-textarea" bind:value={field.choices} placeholder="One choice per line" rows="3"></textarea>
                      </div>
                    {:else if field.type === 'relationship'}
                      <div class="cs-opt-grid">
                        <div class="cs-opt-row">
                          <label class="cs-opt-label">Collection</label>
                          <select class="input cs-opt-input" bind:value={field.relCollection}>
                            <option value="">Select collection...</option>
                            {#each allCollections.filter(c => c.slug !== formSlug) as c}
                              <option value={c.slug}>{c.name}</option>
                            {/each}
                          </select>
                        </div>
                        <div class="cs-opt-row">
                          <label class="cs-opt-label">Max Items</label>
                          <input class="input cs-opt-input" type="number" bind:value={field.relMax} placeholder="0 = unlimited" min="0" />
                        </div>
                      </div>
                      <div class="cs-opt-row cs-opt-row-full">
                        <label class="cs-opt-label">Allow Multiple</label>
                        <label class="cs-checkbox-label">
                          <input type="checkbox" bind:checked={field.relMultiple} />
                          <span>Select more than one item</span>
                        </label>
                      </div>
                    {:else if field.type === 'repeater'}
                      <!-- Visual repeater sub-field builder -->
                      <div class="cs-repeater-builder">
                        <div class="cs-repeater-header">
                          <span class="cs-opt-label" style="margin-bottom: 0;">Sub-fields</span>
                          <button
                            class="cs-pill-toggle"
                            class:active={repeaterJsonMode[i]}
                            onclick={() => { repeaterJsonMode = { ...repeaterJsonMode, [i]: !repeaterJsonMode[i] }; if (!repeaterJsonMode[i]) syncRepeaterJsonToVisual(i); }}
                            type="button"
                          >
                            <span class="cs-pill-toggle-option" class:selected={!repeaterJsonMode[i]}>Visual</span>
                            <span class="cs-pill-toggle-option" class:selected={repeaterJsonMode[i]}>JSON</span>
                          </button>
                        </div>

                        {#if repeaterJsonMode[i]}
                          <textarea
                            class="input cs-opt-input cs-json-textarea"
                            bind:value={field.repeaterFields}
                            placeholder={'[{"name": "day", "type": "select", "label": "Day", "options": ["Mon","Tue"]}]'}
                            rows="6"
                          ></textarea>
                          <p class="cs-hint" style="margin-top: 6px;">JSON array. Each entry: name, type, label. For select: add options array.</p>
                        {:else}
                          {@const visual = getRepeaterVisual(field)}
                          {#if visual.length === 0 && (!field.repeaterVisual || field.repeaterVisual.length === 0)}
                            <p class="cs-hint" style="padding: 12px 0 4px;">No sub-fields defined yet.</p>
                          {:else}
                            <div class="cs-subfield-list">
                              {#each (field.repeaterVisual && field.repeaterVisual.length > 0 ? field.repeaterVisual : visual) as sub, si}
                                <div class="cs-subfield-item">
                                  <div class="cs-subfield-row">
                                    <input
                                      class="input cs-subfield-label"
                                      type="text"
                                      bind:value={sub.label}
                                      oninput={() => autoSubFieldName(i, si)}
                                      placeholder="Sub-field label"
                                    />
                                    <select class="input cs-subfield-type" bind:value={sub.type} onchange={() => syncRepeaterVisualToJson(i)}>
                                      <option value="text">Text</option>
                                      <option value="textarea">Textarea</option>
                                      <option value="select">Select</option>
                                      <option value="toggle">Toggle</option>
                                      <option value="number">Number</option>
                                      <option value="date">Date</option>
                                      <option value="image">Image</option>
                                    </select>
                                    <button class="cs-action-btn cs-action-btn-danger cs-action-btn-sm" onclick={() => removeRepeaterSubField(i, si)} aria-label="Remove sub-field" type="button">
                                      <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                    </button>
                                  </div>
                                  {#if sub.type === 'select'}
                                    <div class="cs-subfield-options-row">
                                      <input
                                        class="input cs-opt-input"
                                        type="text"
                                        value={Array.isArray(sub.options) ? sub.options.join(', ') : (sub.options || '')}
                                        oninput={(e) => {
                                          sub.options = e.target.value.split(',').map(o => o.trim()).filter(Boolean);
                                          syncRepeaterVisualToJson(i);
                                        }}
                                        placeholder="Option 1, Option 2, Option 3"
                                      />
                                      <span class="cs-hint">Comma-separated options</span>
                                    </div>
                                  {/if}
                                  <span class="cs-machine-name cs-subfield-slug">{sub.name || '---'}</span>
                                </div>
                              {/each}
                            </div>
                          {/if}
                          <button class="cs-add-subfield-btn" onclick={() => addRepeaterSubField(i)} type="button">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                            Add sub-field
                          </button>
                        {/if}
                      </div>
                    {:else if field.type === 'flexible'}
                      <div class="cs-opt-row cs-opt-row-full">
                        <label class="cs-opt-label">Layouts (JSON)</label>
                        <textarea class="input cs-opt-input cs-json-textarea" bind:value={field.flexLayouts} placeholder="Paste layout JSON here..." rows="6"></textarea>
                        <p class="cs-hint" style="margin-top: 6px;">Define layout types with named sub-fields.</p>
                      </div>
                    {/if}

                    <!-- Conditional Logic -->
                    <div class="cs-conditions-section">
                      <div class="cs-conditions-header">
                        <span class="cs-opt-label" style="margin-bottom: 0;">Conditional Logic</span>
                        <label class="cs-checkbox-label cs-checkbox-sm">
                          <input type="checkbox" checked={field.conditions && field.conditions.length > 0} onchange={(e) => {
                            if (e.target.checked) {
                              field.conditions = [{ field: '', operator: '==', value: '' }];
                            } else {
                              field.conditions = [];
                            }
                          }} />
                          <span>Enable</span>
                        </label>
                      </div>
                      {#if field.conditions && field.conditions.length > 0}
                        <div class="cs-conditions-list">
                          {#each field.conditions as cond, ci}
                            <div class="cs-condition-row">
                              <select class="input cs-condition-input" bind:value={cond.field}>
                                <option value="">Select field...</option>
                                {#each formSchema.filter((f2, fi) => fi !== i) as otherField}
                                  <option value={otherField.name || slugifyField(otherField.label)}>{otherField.label || otherField.name}</option>
                                {/each}
                              </select>
                              <select class="input cs-condition-op" bind:value={cond.operator}>
                                <option value="==">equals</option>
                                <option value="!=">not equal</option>
                                <option value="not_empty">has value</option>
                                <option value="empty">is empty</option>
                              </select>
                              {#if cond.operator === '==' || cond.operator === '!='}
                                <input class="input cs-condition-input" type="text" bind:value={cond.value} placeholder="Value" />
                              {/if}
                              <button class="cs-action-btn cs-action-btn-danger cs-action-btn-sm" onclick={() => { field.conditions = field.conditions.filter((_, idx) => idx !== ci); }} type="button">
                                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                              </button>
                            </div>
                          {/each}
                          <button class="cs-add-condition-btn" onclick={() => { field.conditions = [...field.conditions, { field: '', operator: '==', value: '' }]; }} type="button">
                            + Add condition
                          </button>
                        </div>
                      {/if}
                    </div>
                  </div>
                {/if}
              </div>
            {/each}
          </div>

          <button class="cs-add-field-btn" onclick={addSchemaField}>
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Add Field
          </button>
        </section>

        <!-- Section: Advanced -->
        <section class="cs-section">
          <div class="cs-section-header">
            <h2 class="cs-section-label">Advanced</h2>
          </div>

          <div class="cs-advanced-option">
            <label class="cs-switch-label">
              <input type="checkbox" bind:checked={formRequireReview} />
              <span class="cs-switch-text">Require review before publishing</span>
            </label>
            <span class="cs-hint">Editors must submit for review; admins approve or reject.</span>
          </div>

          {#if availableWorkflows.length > 0}
            <div class="cs-field" style="margin-top: 16px; max-width: 320px;">
              <label class="cs-label" for="cs-workflow">Workflow</label>
              <select id="cs-workflow" class="input" value={formWorkflowId || ''} onchange={(e) => { formWorkflowId = e.target.value ? Number(e.target.value) : null; }}>
                <option value="">Default (Simple)</option>
                {#each availableWorkflows as wf}
                  <option value={wf.id}>{wf.name} ({wf.stages.length} stages)</option>
                {/each}
              </select>
              <span class="cs-hint">
                Assign a workflow to define custom approval stages.
                <button class="cs-text-link" onclick={() => navigate('workflows')} type="button">Manage workflows</button>
              </span>
            </div>
          {/if}

          <!-- Lodge Settings -->
          <div class="cs-lodge-section">
            <div class="cs-lodge-header">
              <div class="cs-lodge-header-text">
                <span class="cs-lodge-title">Lodge</span>
                <span class="cs-hint">Allow members to create and manage their own content.</span>
              </div>
              <button
                class="toggle"
                class:active={formLodgeEnabled}
                onclick={() => { formLodgeEnabled = !formLodgeEnabled; }}
                type="button"
              ></button>
            </div>

            {#if formLodgeEnabled}
              <div class="cs-lodge-options">
                <div class="cs-lodge-grid">
                  <div class="cs-lodge-toggle-row">
                    <label class="cs-switch-label">
                      <input type="checkbox" bind:checked={formLodgeConfig.allow_create} />
                      <span class="cs-switch-text">Allow Create</span>
                    </label>
                    <span class="cs-hint">Members can create new items</span>
                  </div>
                  <div class="cs-lodge-toggle-row">
                    <label class="cs-switch-label">
                      <input type="checkbox" bind:checked={formLodgeConfig.allow_edit} />
                      <span class="cs-switch-text">Allow Edit</span>
                    </label>
                    <span class="cs-hint">Members can edit their own items</span>
                  </div>
                  <div class="cs-lodge-toggle-row">
                    <label class="cs-switch-label">
                      <input type="checkbox" bind:checked={formLodgeConfig.allow_delete} />
                      <span class="cs-switch-text">Allow Delete</span>
                    </label>
                    <span class="cs-hint">Members can delete their own items</span>
                  </div>
                  <div class="cs-lodge-toggle-row">
                    <label class="cs-switch-label">
                      <input type="checkbox" bind:checked={formLodgeConfig.require_approval} />
                      <span class="cs-switch-text">Require Approval</span>
                    </label>
                    <span class="cs-hint">Submissions require admin approval</span>
                  </div>
                </div>

                <div class="cs-lodge-field-row">
                  <label class="cs-opt-label">Max Items Per Member</label>
                  <input class="input cs-lodge-number" type="number" min="0" bind:value={formLodgeConfig.max_items_per_member} />
                  <span class="cs-hint">0 = unlimited</span>
                </div>

                {#if formSchema.filter(f => f.name || f.label).length > 0}
                  {@const schemaFields = formSchema.filter(f => f.name || f.label).map(f => f.name || slugifyField(f.label))}
                  <div class="cs-lodge-fields-section">
                    <div class="cs-lodge-field-group">
                      <label class="cs-opt-label">Editable Fields</label>
                      <div class="cs-lodge-field-checks">
                        {#each schemaFields as fieldName}
                          <label class="cs-checkbox-label">
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
                            />
                            <span>{fieldName}</span>
                          </label>
                        {/each}
                      </div>
                      <span class="cs-hint">Fields members can edit. Leave empty to allow all.</span>
                    </div>
                    <div class="cs-lodge-field-group">
                      <label class="cs-opt-label">Read-only Fields</label>
                      <div class="cs-lodge-field-checks">
                        {#each schemaFields as fieldName}
                          <label class="cs-checkbox-label">
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
                            />
                            <span>{fieldName}</span>
                          </label>
                        {/each}
                      </div>
                      <span class="cs-hint">Fields visible to members but not editable.</span>
                    </div>
                  </div>
                {/if}
              </div>
            {/if}
          </div>
        </section>
      </div>
    </div>
  </div>
{/if}

<style>
  /* ── Page layout ─────────────────────────────────── */
  .cs-page {
    display: flex;
    flex-direction: column;
    height: 100%;
    min-height: 0;
  }

  .cs-topbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 24px;
    border-bottom: 1px solid var(--border-primary);
    background: var(--bg-primary);
    position: sticky;
    top: 0;
    z-index: 10;
  }

  .cs-topbar-left { display: flex; align-items: center; gap: var(--space-md); }
  .cs-topbar-right { display: flex; align-items: center; gap: var(--space-sm); }

  .cs-back {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 13px;
    font-weight: 500;
    color: var(--text-secondary);
    background: none;
    border: none;
    cursor: pointer;
    padding: 4px 8px;
    border-radius: var(--radius-sm);
    transition: color 0.15s, background 0.15s;
  }
  .cs-back:hover { color: var(--text-primary); background: var(--bg-hover); }

  .cs-body {
    flex: 1;
    overflow-y: auto;
    padding: 40px 48px 80px;
  }

  .cs-content {
    max-width: 720px;
    margin: 0 auto;
  }

  .cs-title {
    font-family: var(--font-serif);
    font-size: 28px;
    font-weight: 600;
    color: var(--text-primary);
    margin: 0 0 36px;
    letter-spacing: -0.01em;
  }

  /* ── Sections ────────────────────────────────────── */
  .cs-section {
    margin-bottom: 48px;
  }

  .cs-section-header {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    margin-bottom: 20px;
    padding-bottom: 10px;
    border-bottom: 1px solid var(--border-secondary);
  }

  .cs-section-label {
    font-size: 11px;
    font-weight: 600;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--text-tertiary);
    margin: 0;
  }

  .cs-field-count {
    font-size: 11px;
    color: var(--text-light);
    font-weight: 500;
  }

  /* ── Settings grid ───────────────────────────────── */
  .cs-settings-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
    margin-bottom: 16px;
  }

  .cs-settings-grid-three {
    grid-template-columns: 1fr 1fr 1fr;
  }

  .cs-field {
    display: flex;
    flex-direction: column;
    gap: 4px;
  }

  .cs-label {
    font-size: 11px;
    font-weight: 600;
    color: var(--text-tertiary);
    text-transform: uppercase;
    letter-spacing: 0.04em;
  }

  .cs-mono-input {
    font-family: var(--font-mono);
    font-size: 13px;
  }

  .cs-slug-display {
    padding: 7px 0;
    display: flex;
    align-items: center;
    gap: 10px;
  }

  .cs-slug-display code {
    font-size: 13px;
    font-family: var(--font-mono);
    color: var(--text-secondary);
    background: var(--bg-tertiary);
    padding: 2px 8px;
    border-radius: 4px;
  }

  .cs-hint {
    font-size: 11px;
    color: var(--text-light);
    line-height: 1.4;
  }

  /* ── Schema field cards ──────────────────────────── */
  .cs-field-list {
    display: flex;
    flex-direction: column;
    gap: 2px;
  }

  .cs-schema-card {
    border-radius: var(--radius-sm);
    padding: 10px 12px 8px;
    background: transparent;
    border: 1px solid transparent;
    transition: all 0.15s ease;
  }

  .cs-schema-card:hover {
    background: var(--bg-secondary);
    border-color: var(--border-secondary);
  }

  .cs-schema-card-expanded {
    background: var(--bg-secondary);
    border-color: var(--border-primary);
    padding-bottom: 14px;
  }

  .cs-sortable-ghost {
    opacity: 0.35;
  }

  .cs-schema-header {
    display: flex;
    align-items: center;
    gap: 8px;
  }

  .cs-drag-handle {
    cursor: grab;
    color: var(--text-light);
    display: flex;
    align-items: center;
    padding: 4px 2px;
    opacity: 0;
    transition: opacity 0.15s;
    flex-shrink: 0;
  }

  .cs-schema-card:hover .cs-drag-handle {
    opacity: 0.5;
  }

  .cs-drag-handle:hover {
    opacity: 1 !important;
  }

  .cs-schema-main {
    flex: 1;
    min-width: 0;
  }

  .cs-schema-label-input {
    width: 100%;
    font-size: 14px;
    font-weight: 500;
    color: var(--text-primary);
    background: transparent;
    border: none;
    outline: none;
    padding: 2px 0;
    border-bottom: 1px solid transparent;
    transition: border-color 0.15s;
  }

  .cs-schema-label-input::placeholder {
    color: var(--text-light);
    font-weight: 400;
  }

  .cs-schema-label-input:hover {
    border-bottom-color: var(--border-primary);
  }

  .cs-schema-label-input:focus {
    border-bottom-color: var(--accent);
  }

  /* ── Type pill ───────────────────────────────────── */
  .cs-type-pill {
    flex-shrink: 0;
    position: relative;
  }

  .cs-type-select {
    appearance: none;
    -webkit-appearance: none;
    background: transparent;
    border: 1px solid transparent;
    color: var(--pill-color, var(--text-tertiary));
    font-size: 11px;
    font-weight: 600;
    letter-spacing: 0.03em;
    text-transform: uppercase;
    padding: 3px 20px 3px 8px;
    border-radius: 4px;
    cursor: pointer;
    outline: none;
    transition: all 0.15s;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='10' viewBox='0 0 24 24' fill='none' stroke='%238A857D' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 4px center;
  }

  .cs-type-select:hover {
    background-color: var(--bg-hover);
    border-color: var(--border-primary);
  }

  .cs-type-select:focus {
    border-color: var(--accent);
    background-color: var(--bg-primary);
  }

  /* ── Actions ─────────────────────────────────────── */
  .cs-schema-actions {
    display: flex;
    align-items: center;
    gap: 0;
    flex-shrink: 0;
    opacity: 0;
    transition: opacity 0.15s;
  }

  .cs-schema-card:hover .cs-schema-actions {
    opacity: 1;
  }

  .cs-schema-card-expanded .cs-schema-actions {
    opacity: 1;
  }

  .cs-action-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    background: none;
    border: none;
    border-radius: 6px;
    color: var(--text-tertiary);
    cursor: pointer;
    transition: all 0.12s;
  }

  .cs-action-btn:hover {
    color: var(--text-primary);
    background: var(--bg-hover);
  }

  .cs-action-btn-active {
    color: var(--accent);
  }

  .cs-action-btn-danger:hover {
    color: var(--danger);
    background: var(--danger-soft);
  }

  .cs-action-btn-sm {
    width: 24px;
    height: 24px;
  }

  .cs-required-toggle {
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 24px;
    height: 24px;
    flex-shrink: 0;
    border-radius: 6px;
    transition: background 0.12s;
  }

  .cs-required-toggle:hover {
    background: var(--bg-hover);
  }

  .cs-required-star {
    font-size: 16px;
    font-weight: 700;
    color: var(--text-light);
    transition: all 0.15s;
  }

  .cs-required-star.active {
    color: var(--danger);
  }

  /* ── Machine name (slug) ─────────────────────────── */
  .cs-schema-meta {
    margin-top: 1px;
    padding-left: 22px;
  }

  .cs-machine-name {
    font-size: 10px;
    color: var(--text-light);
    font-family: var(--font-mono);
    letter-spacing: 0.02em;
  }

  /* ── Expanded options panel ──────────────────────── */
  .cs-schema-options {
    margin-top: 12px;
    margin-left: 22px;
    padding: 16px;
    background: var(--bg-tertiary);
    border-radius: var(--radius-sm);
    border: 1px solid var(--border-secondary);
    display: flex;
    flex-direction: column;
    gap: 12px;
  }

  .cs-opt-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
  }

  .cs-opt-row {
    display: flex;
    flex-direction: column;
    gap: 3px;
  }

  .cs-opt-row-full {
    grid-column: 1 / -1;
  }

  .cs-opt-label {
    font-size: 11px;
    font-weight: 600;
    color: var(--text-tertiary);
    text-transform: uppercase;
    letter-spacing: 0.04em;
    margin-bottom: 1px;
  }

  .cs-opt-input {
    height: 32px;
    font-size: 13px;
  }

  .cs-opt-textarea {
    height: auto;
    min-height: 64px;
    resize: vertical;
  }

  .cs-json-textarea {
    font-family: var(--font-mono);
    font-size: 12px;
    height: auto;
    min-height: 100px;
    resize: vertical;
    line-height: 1.5;
  }

  /* ── Checkbox label ──────────────────────────────── */
  .cs-checkbox-label {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 13px;
    color: var(--text-secondary);
    cursor: pointer;
    margin: 0;
  }

  .cs-checkbox-label input[type="checkbox"] {
    accent-color: var(--accent);
  }

  .cs-checkbox-sm {
    font-size: 12px;
  }

  .cs-checkbox-sm span {
    font-size: 12px;
  }

  /* ── Conditions ──────────────────────────────────── */
  .cs-conditions-section {
    padding-top: 12px;
    border-top: 1px solid var(--border-secondary);
  }

  .cs-conditions-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 8px;
  }

  .cs-conditions-list {
    display: flex;
    flex-direction: column;
    gap: 6px;
  }

  .cs-condition-row {
    display: flex;
    gap: 6px;
    align-items: center;
  }

  .cs-condition-input {
    flex: 1;
    font-size: 12px;
    padding: 6px 8px;
    height: 30px;
  }

  .cs-condition-op {
    flex: 0 0 96px;
    font-size: 12px;
    padding: 6px 8px;
    height: 30px;
  }

  .cs-add-condition-btn {
    background: none;
    border: none;
    color: var(--accent);
    font-size: 11px;
    font-weight: 500;
    cursor: pointer;
    padding: 4px 0;
    margin-top: 2px;
  }

  .cs-add-condition-btn:hover {
    text-decoration: underline;
  }

  /* ── Repeater builder ────────────────────────────── */
  .cs-repeater-builder {
    padding-top: 4px;
  }

  .cs-repeater-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 10px;
  }

  /* Pill toggle (Visual / JSON) */
  .cs-pill-toggle {
    display: inline-flex;
    background: var(--bg-hover);
    border: none;
    border-radius: 6px;
    padding: 2px;
    cursor: pointer;
    gap: 0;
  }

  .cs-pill-toggle-option {
    font-size: 11px;
    font-weight: 500;
    padding: 3px 10px;
    border-radius: 4px;
    color: var(--text-tertiary);
    transition: all 0.15s;
  }

  .cs-pill-toggle-option.selected {
    background: var(--bg-primary);
    color: var(--text-primary);
    box-shadow: var(--shadow-sm);
  }

  /* Sub-field list */
  .cs-subfield-list {
    border-left: 2px solid var(--accent-soft);
    padding-left: 12px;
    margin-left: 4px;
    display: flex;
    flex-direction: column;
    gap: 6px;
  }

  .cs-subfield-item {
    display: flex;
    flex-direction: column;
    gap: 2px;
  }

  .cs-subfield-row {
    display: flex;
    align-items: center;
    gap: 6px;
  }

  .cs-subfield-label {
    flex: 1;
    height: 30px;
    font-size: 13px;
  }

  .cs-subfield-type {
    flex: 0 0 100px;
    height: 30px;
    font-size: 12px;
  }

  .cs-subfield-slug {
    padding-left: 2px;
  }

  .cs-subfield-options-row {
    padding-left: 0;
    display: flex;
    flex-direction: column;
    gap: 2px;
  }

  .cs-add-subfield-btn {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    background: none;
    border: none;
    color: var(--accent);
    font-size: 12px;
    font-weight: 500;
    cursor: pointer;
    padding: 6px 0;
    margin-top: 4px;
  }

  .cs-add-subfield-btn:hover {
    text-decoration: underline;
  }

  /* ── Add field button ────────────────────────────── */
  .cs-add-field-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: none;
    border: 1px dashed var(--border-primary);
    color: var(--text-tertiary);
    font-size: 13px;
    font-weight: 500;
    cursor: pointer;
    padding: 10px 16px;
    border-radius: var(--radius-sm);
    margin-top: 8px;
    transition: all 0.15s;
    width: 100%;
    justify-content: center;
  }

  .cs-add-field-btn:hover {
    border-color: var(--accent);
    color: var(--accent);
    background: var(--accent-soft);
  }

  /* ── Advanced section ────────────────────────────── */
  .cs-advanced-option {
    display: flex;
    flex-direction: column;
    gap: 4px;
  }

  .cs-switch-label {
    display: flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
    font-size: 13px;
    margin: 0;
  }

  .cs-switch-label input[type="checkbox"] {
    accent-color: var(--accent);
  }

  .cs-switch-text {
    color: var(--text-secondary);
    font-weight: 500;
  }

  .cs-text-link {
    background: none;
    border: none;
    color: var(--accent);
    font-size: 11px;
    cursor: pointer;
    padding: 0;
  }
  .cs-text-link:hover { text-decoration: underline; }

  /* ── Lodge section ───────────────────────────────── */
  .cs-lodge-section {
    margin-top: 24px;
    padding: 16px 20px;
    border-radius: var(--radius-sm);
    border: 1px solid var(--border-secondary);
    background: var(--bg-secondary);
  }

  .cs-lodge-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
  }

  .cs-lodge-header-text {
    display: flex;
    flex-direction: column;
    gap: 2px;
  }

  .cs-lodge-title {
    font-size: 13px;
    font-weight: 600;
    color: var(--text-primary);
    letter-spacing: 0.01em;
  }

  .cs-lodge-options {
    display: flex;
    flex-direction: column;
    gap: 16px;
    margin-top: 16px;
    padding-top: 16px;
    border-top: 1px solid var(--border-secondary);
  }

  .cs-lodge-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
  }

  .cs-lodge-toggle-row {
    display: flex;
    flex-direction: column;
    gap: 2px;
  }

  .cs-lodge-field-row {
    display: flex;
    align-items: center;
    gap: 10px;
  }

  .cs-lodge-number {
    width: 80px;
    height: 30px;
    font-size: 13px;
    text-align: center;
  }

  .cs-lodge-fields-section {
    display: flex;
    flex-direction: column;
    gap: 14px;
  }

  .cs-lodge-field-group {
    display: flex;
    flex-direction: column;
    gap: 6px;
  }

  .cs-lodge-field-checks {
    display: flex;
    flex-wrap: wrap;
    gap: 4px 14px;
  }

  /* ── Responsive ──────────────────────────────────── */
  @media (max-width: 768px) {
    .cs-body {
      padding: 24px 16px 60px;
    }

    .cs-content {
      max-width: 100%;
    }

    .cs-settings-grid,
    .cs-settings-grid-three {
      grid-template-columns: 1fr;
    }

    .cs-opt-grid {
      grid-template-columns: 1fr;
    }

    .cs-lodge-grid {
      grid-template-columns: 1fr;
    }

    .cs-schema-header {
      flex-wrap: wrap;
    }
  }
</style>
