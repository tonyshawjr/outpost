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
      handle: '.schema-drag-handle',
      ghostClass: 'schema-sortable-ghost',
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
  <div>
    <div class="page-header">
      <div class="page-header-icon sage">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
      </div>
      <div class="page-header-content">
        <h1 class="page-title">{formName || 'Collection Schema'}</h1>
        <p class="page-subtitle">
          <button class="schema-back-link" onclick={goBack} type="button">Collections</button>
          <span style="color: var(--text-light); margin: 0 4px;">/</span>
          <span>{formName}</span>
          <span style="color: var(--text-light); margin: 0 4px;">/</span>
          <span>Schema</span>
        </p>
      </div>
      <div class="page-header-actions">
        <button class="btn btn-primary" onclick={saveCollection} disabled={saving || !formName}>
          {saving ? 'Saving...' : 'Save Changes'}
        </button>
      </div>
    </div>

    <!-- Collection Settings -->
    <div class="form-group">
      <label class="form-label">Collection Settings</label>
      <div class="schema-settings-grid">
        <div class="form-group" style="margin-bottom: 0;">
          <label class="form-label" for="cs-name" style="font-size: var(--font-size-xs); color: var(--text-tertiary);">Name</label>
          <input id="cs-name" class="input" type="text" bind:value={formName} placeholder="Blog Posts" />
        </div>
        <div class="form-group" style="margin-bottom: 0;">
          <label class="form-label" for="cs-singular" style="font-size: var(--font-size-xs); color: var(--text-tertiary);">Singular Name</label>
          <input id="cs-singular" class="input" type="text" bind:value={formSingularName} placeholder="Blog Post" />
        </div>
      </div>
      <div class="schema-settings-grid" style="margin-top: var(--space-md);">
        <div class="form-group" style="margin-bottom: 0;">
          <label class="form-label" style="font-size: var(--font-size-xs); color: var(--text-tertiary);">Slug</label>
          <div style="padding: 6px 0;">
            <code style="font-size: 12px; font-family: var(--font-mono); color: var(--text-secondary); background: var(--bg-tertiary); padding: 2px 8px; border-radius: 4px;">{formSlug}</code>
            <span style="margin-left: 6px; font-size: var(--font-size-xs); color: var(--text-tertiary);">Cannot be changed</span>
          </div>
        </div>
        <div class="form-group" style="margin-bottom: 0;">
          <label class="form-label" for="cs-url" style="font-size: var(--font-size-xs); color: var(--text-tertiary);">URL Pattern</label>
          <input id="cs-url" class="input" type="text" bind:value={formUrlPattern} placeholder="/{formSlug}/{'{slug}'}" style="font-family: var(--font-mono); font-size: 13px;" />
        </div>
      </div>
      <div class="schema-settings-grid schema-settings-grid-three" style="margin-top: var(--space-md);">
        <div class="form-group" style="margin-bottom: 0;">
          <label class="form-label" for="cs-sort-field" style="font-size: var(--font-size-xs); color: var(--text-tertiary);">Sort Field</label>
          <select id="cs-sort-field" class="input" bind:value={formSortField}>
            <option value="created_at">Created At</option>
            <option value="updated_at">Updated At</option>
            <option value="published_at">Published At</option>
            <option value="slug">Slug</option>
            <option value="sort_order">Sort Order</option>
          </select>
        </div>
        <div class="form-group" style="margin-bottom: 0;">
          <label class="form-label" for="cs-sort-dir" style="font-size: var(--font-size-xs); color: var(--text-tertiary);">Sort Direction</label>
          <select id="cs-sort-dir" class="input" bind:value={formSortDirection}>
            <option value="DESC">Descending</option>
            <option value="ASC">Ascending</option>
          </select>
        </div>
        <div class="form-group" style="margin-bottom: 0;">
          <label class="form-label" for="cs-per-page" style="font-size: var(--font-size-xs); color: var(--text-tertiary);">Per Page</label>
          <input id="cs-per-page" class="input" type="number" min="1" max="100" bind:value={formItemsPerPage} />
        </div>
      </div>
    </div>

    <!-- Content Fields -->
    <div class="form-group">
      <label class="form-label">Content Fields</label>
      <p style="font-size: var(--font-size-xs); color: var(--text-tertiary); margin-bottom: var(--space-sm);">
        Define the fields each item in this collection will have. {formSchema.length} {formSchema.length === 1 ? 'field' : 'fields'} defined.
      </p>

      <div bind:this={fieldListEl}>
        {#each formSchema as field, i (i)}
          <div class="schema-field-card" class:schema-field-card-expanded={expandedFields[i]}>
            <div class="schema-field-header">
              <span class="schema-drag-handle" title="Drag to reorder">
                <svg width="10" height="14" viewBox="0 0 10 14" fill="currentColor">
                  <circle cx="2.5" cy="2" r="1.2"/><circle cx="7.5" cy="2" r="1.2"/>
                  <circle cx="2.5" cy="7" r="1.2"/><circle cx="7.5" cy="7" r="1.2"/>
                  <circle cx="2.5" cy="12" r="1.2"/><circle cx="7.5" cy="12" r="1.2"/>
                </svg>
              </span>
              <div class="schema-field-main">
                <input class="input schema-field-label" type="text" bind:value={field.label} oninput={() => autoFieldName(i)} placeholder="Field label" />
                <select class="input" bind:value={field.type} style="flex: 0 0 130px;">
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
                <label class="schema-required-toggle" title="Required">
                  <input type="checkbox" bind:checked={field.required} style="display:none;" />
                  <span class="schema-required-star" class:active={field.required}>*</span>
                </label>
              </div>
              <div class="schema-field-actions">
                <button class="btn btn-ghost btn-sm" class:schema-gear-active={expandedFields[i]} onclick={() => toggleFieldExpand(i)} aria-label="Field options" title="Field options" type="button">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83 0 2 2 0 010-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"/></svg>
                </button>
                <button class="btn btn-ghost btn-sm" onclick={() => removeSchemaField(i)} aria-label="Remove field" type="button">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
              </div>
            </div>
            <div class="schema-field-meta">
              <span style="font-size: 11px; color: var(--text-tertiary); font-family: var(--font-mono);">{field.name || '---'}</span>
            </div>
            {#if expandedFields[i]}
              <div class="schema-field-options">
                <div class="schema-opt-row">
                  <label class="schema-opt-label">Machine Name</label>
                  <input class="input schema-opt-input" type="text" bind:value={field.name} placeholder="auto_generated" />
                </div>
                <div class="schema-opt-row">
                  <label class="schema-opt-label">Placeholder</label>
                  <input class="input schema-opt-input" type="text" bind:value={field.placeholder} placeholder="Placeholder text..." />
                </div>
                <div class="schema-opt-row">
                  <label class="schema-opt-label">Description</label>
                  <input class="input schema-opt-input" type="text" bind:value={field.description} placeholder="Help text shown below field" />
                </div>
                <div class="schema-opt-row">
                  <label class="schema-opt-label">Default Value</label>
                  <input class="input schema-opt-input" type="text" bind:value={field.defaultValue} placeholder="Default value" />
                </div>
                {#if field.type === 'select'}
                  <div class="schema-opt-row">
                    <label class="schema-opt-label">Choices</label>
                    <textarea class="input schema-opt-input" bind:value={field.choices} placeholder="One choice per line" rows="3" style="height: auto;"></textarea>
                  </div>
                {:else if field.type === 'relationship'}
                  <div class="schema-opt-row">
                    <label class="schema-opt-label">Collection</label>
                    <select class="input schema-opt-input" bind:value={field.relCollection}>
                      <option value="">Select collection...</option>
                      {#each allCollections.filter(c => c.slug !== formSlug) as c}
                        <option value={c.slug}>{c.name}</option>
                      {/each}
                    </select>
                  </div>
                  <div class="schema-opt-row">
                    <label class="schema-opt-label">Allow Multiple</label>
                    <label style="display: flex; align-items: center; gap: 6px; font-size: 13px; color: var(--text-secondary);">
                      <input type="checkbox" bind:checked={field.relMultiple} style="accent-color: var(--accent);" /> Select more than one item
                    </label>
                  </div>
                  <div class="schema-opt-row">
                    <label class="schema-opt-label">Max Items</label>
                    <input class="input schema-opt-input" type="number" bind:value={field.relMax} placeholder="0 = unlimited" min="0" style="max-width: 120px;" />
                  </div>
                {:else if field.type === 'repeater'}
                  <!-- Visual repeater sub-field builder -->
                  <div class="schema-opt-row" style="margin-top: 4px; border-top: 1px solid var(--border-primary); padding-top: 10px;">
                    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px;">
                      <label class="schema-opt-label" style="margin-bottom: 0;">Sub-fields</label>
                      <button
                        class="schema-pill-toggle"
                        onclick={() => { repeaterJsonMode = { ...repeaterJsonMode, [i]: !repeaterJsonMode[i] }; if (!repeaterJsonMode[i]) syncRepeaterJsonToVisual(i); }}
                        type="button"
                      >
                        <span class="schema-pill-opt" class:selected={!repeaterJsonMode[i]}>Visual</span>
                        <span class="schema-pill-opt" class:selected={repeaterJsonMode[i]}>JSON</span>
                      </button>
                    </div>

                    {#if repeaterJsonMode[i]}
                      <textarea
                        class="input schema-opt-input"
                        bind:value={field.repeaterFields}
                        placeholder={'[{"name": "day", "type": "select", "label": "Day", "options": ["Mon","Tue"]}]'}
                        rows="6"
                        style="height: auto; font-family: var(--font-mono); font-size: 12px;"
                      ></textarea>
                      <p style="font-size: 11px; color: var(--text-tertiary); margin-top: 4px;">JSON array. Each entry: name, type, label. For select: add options array.</p>
                    {:else}
                      {@const visual = getRepeaterVisual(field)}
                      {#if visual.length === 0 && (!field.repeaterVisual || field.repeaterVisual.length === 0)}
                        <p style="font-size: 12px; color: var(--text-tertiary); padding: 8px 0 4px;">No sub-fields defined yet.</p>
                      {:else}
                        <div class="schema-subfield-list">
                          {#each (field.repeaterVisual && field.repeaterVisual.length > 0 ? field.repeaterVisual : visual) as sub, si}
                            <div class="schema-subfield-item">
                              <div class="schema-subfield-row">
                                <input
                                  class="input"
                                  type="text"
                                  bind:value={sub.label}
                                  oninput={() => autoSubFieldName(i, si)}
                                  placeholder="Sub-field label"
                                  style="flex: 1; height: 30px; font-size: 13px;"
                                />
                                <select class="input" bind:value={sub.type} onchange={() => syncRepeaterVisualToJson(i)} style="flex: 0 0 100px; height: 30px; font-size: 12px;">
                                  <option value="text">Text</option>
                                  <option value="textarea">Textarea</option>
                                  <option value="select">Select</option>
                                  <option value="toggle">Toggle</option>
                                  <option value="number">Number</option>
                                  <option value="date">Date</option>
                                  <option value="image">Image</option>
                                </select>
                                <button class="btn btn-ghost btn-sm" onclick={() => removeRepeaterSubField(i, si)} aria-label="Remove sub-field" type="button" style="padding: 4px;">
                                  <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                </button>
                              </div>
                              {#if sub.type === 'select'}
                                <div style="display: flex; flex-direction: column; gap: 2px;">
                                  <input
                                    class="input"
                                    type="text"
                                    value={Array.isArray(sub.options) ? sub.options.join(', ') : (sub.options || '')}
                                    oninput={(e) => {
                                      sub.options = e.target.value.split(',').map(o => o.trim()).filter(Boolean);
                                      syncRepeaterVisualToJson(i);
                                    }}
                                    placeholder="Option 1, Option 2, Option 3"
                                    style="height: 30px; font-size: 13px;"
                                  />
                                  <span style="font-size: 11px; color: var(--text-tertiary);">Comma-separated options</span>
                                </div>
                              {/if}
                              <span style="font-size: 11px; color: var(--text-tertiary); font-family: var(--font-mono); padding-left: 2px;">{sub.name || '---'}</span>
                            </div>
                          {/each}
                        </div>
                      {/if}
                      <button class="btn btn-ghost btn-sm" onclick={() => addRepeaterSubField(i)} type="button" style="font-size: 11px; margin-top: 4px; color: var(--accent);">
                        + Add sub-field
                      </button>
                    {/if}
                  </div>
                {:else if field.type === 'flexible'}
                  <div class="schema-opt-row">
                    <label class="schema-opt-label">Layouts (JSON)</label>
                    <textarea class="input schema-opt-input" bind:value={field.flexLayouts} placeholder="Paste layout JSON here..." rows="6" style="height: auto; font-family: var(--font-mono); font-size: 12px;"></textarea>
                    <p style="font-size: 11px; color: var(--text-tertiary); margin-top: 4px;">Define layout types with named sub-fields.</p>
                  </div>
                {/if}
                <!-- Conditional Logic -->
                <div class="schema-opt-row" style="margin-top: 8px; border-top: 1px solid var(--border-primary); padding-top: 10px;">
                  <label class="schema-opt-label" style="display: flex; align-items: center; gap: 6px;">
                    Conditional Logic
                    <label style="display: inline-flex; align-items: center; gap: 4px; font-size: 12px; color: var(--text-secondary); font-weight: normal;">
                      <input type="checkbox" checked={field.conditions && field.conditions.length > 0} onchange={(e) => {
                        if (e.target.checked) {
                          field.conditions = [{ field: '', operator: '==', value: '' }];
                        } else {
                          field.conditions = [];
                        }
                      }} style="accent-color: var(--accent);" /> Enable
                    </label>
                  </label>
                  {#if field.conditions && field.conditions.length > 0}
                    {#each field.conditions as cond, ci}
                      <div style="display: flex; gap: 6px; align-items: center; margin-top: 4px;">
                        <select class="input" style="flex: 1; font-size: 12px; padding: 6px 8px;" bind:value={cond.field}>
                          <option value="">Select field...</option>
                          {#each formSchema.filter((f2, fi) => fi !== i) as otherField}
                            <option value={otherField.name || slugifyField(otherField.label)}>{otherField.label || otherField.name}</option>
                          {/each}
                        </select>
                        <select class="input" style="flex: 0 0 90px; font-size: 12px; padding: 6px 8px;" bind:value={cond.operator}>
                          <option value="==">equals</option>
                          <option value="!=">not equal</option>
                          <option value="not_empty">has value</option>
                          <option value="empty">is empty</option>
                        </select>
                        {#if cond.operator === '==' || cond.operator === '!='}
                          <input class="input" style="flex: 1; font-size: 12px; padding: 6px 8px;" type="text" bind:value={cond.value} placeholder="Value" />
                        {/if}
                        <button class="btn btn-ghost btn-sm" onclick={() => { field.conditions = field.conditions.filter((_, idx) => idx !== ci); }} type="button" style="padding: 4px;">
                          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                        </button>
                      </div>
                    {/each}
                    <button class="btn btn-ghost btn-sm" onclick={() => { field.conditions = [...field.conditions, { field: '', operator: '==', value: '' }]; }} type="button" style="font-size: 11px; margin-top: 4px; color: var(--accent);">
                      + Add condition
                    </button>
                  {/if}
                </div>
              </div>
            {/if}
          </div>
        {/each}
      </div>

      <button class="btn btn-secondary btn-sm" onclick={addSchemaField} style="margin-top: var(--space-xs);">
        Add Field
      </button>
    </div>

    <!-- Advanced -->
    <div class="form-group">
      <label class="form-label">Advanced</label>
      <div class="form-group" style="display: flex; align-items: center; gap: 8px; margin-bottom: var(--space-md);">
        <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: var(--font-size-sm); color: var(--text-secondary); margin: 0;">
          <input type="checkbox" bind:checked={formRequireReview} style="accent-color: var(--accent);" />
          Require review before publishing
        </label>
        <span style="font-size: var(--font-size-xs); color: var(--text-tertiary);">Editors must submit for review; admins approve or reject.</span>
      </div>

      {#if availableWorkflows.length > 0}
        <div class="form-group">
          <label class="form-label" for="cs-workflow">Workflow</label>
          <select id="cs-workflow" class="input" value={formWorkflowId || ''} onchange={(e) => { formWorkflowId = e.target.value ? Number(e.target.value) : null; }}>
            <option value="">Default (Simple)</option>
            {#each availableWorkflows as wf}
              <option value={wf.id}>{wf.name} ({wf.stages.length} stages)</option>
            {/each}
          </select>
          <span style="font-size: var(--font-size-xs); color: var(--text-tertiary);">
            Assign a workflow to define custom approval stages.
            <a href="#" onclick={(e) => { e.preventDefault(); navigate('workflows'); }} style="color: var(--accent);">Manage workflows</a>
          </span>
        </div>
      {/if}

      <!-- Lodge Settings -->
      <div class="lodge-section">
        <div class="lodge-section-header">
          <span class="lodge-section-label">LODGE</span>
          <button
            class="toggle"
            class:active={formLodgeEnabled}
            onclick={() => { formLodgeEnabled = !formLodgeEnabled; }}
            type="button"
          ></button>
        </div>
        <p style="font-size: var(--font-size-xs); color: var(--text-tertiary); margin: 0 0 var(--space-sm);">Allow members to create and manage their own content in this collection.</p>

        {#if formLodgeEnabled}
          <div class="lodge-options">
            <div class="lodge-toggle-row">
              <label class="lodge-toggle-label">
                <input type="checkbox" bind:checked={formLodgeConfig.allow_create} style="accent-color: var(--accent);" />
                Allow Create
              </label>
              <span class="lodge-toggle-desc">Members can create new items</span>
            </div>
            <div class="lodge-toggle-row">
              <label class="lodge-toggle-label">
                <input type="checkbox" bind:checked={formLodgeConfig.allow_edit} style="accent-color: var(--accent);" />
                Allow Edit
              </label>
              <span class="lodge-toggle-desc">Members can edit their own items</span>
            </div>
            <div class="lodge-toggle-row">
              <label class="lodge-toggle-label">
                <input type="checkbox" bind:checked={formLodgeConfig.allow_delete} style="accent-color: var(--accent);" />
                Allow Delete
              </label>
              <span class="lodge-toggle-desc">Members can delete their own items</span>
            </div>
            <div class="lodge-toggle-row">
              <label class="lodge-toggle-label">
                <input type="checkbox" bind:checked={formLodgeConfig.require_approval} style="accent-color: var(--accent);" />
                Require Approval
              </label>
              <span class="lodge-toggle-desc">Submissions require admin approval before publishing</span>
            </div>
            <div class="lodge-field-row">
              <label class="lodge-field-label">Max Items Per Member</label>
              <input class="input" type="number" min="0" style="width: 100px; height: 30px; font-size: 13px;" bind:value={formLodgeConfig.max_items_per_member} />
              <span style="font-size: var(--font-size-xs); color: var(--text-tertiary);">0 = unlimited</span>
            </div>

            {#if formSchema.filter(f => f.name || f.label).length > 0}
              {@const schemaFields = formSchema.filter(f => f.name || f.label).map(f => f.name || slugifyField(f.label))}
              <div class="lodge-field-row" style="flex-direction: column; align-items: flex-start;">
                <label class="lodge-field-label">Editable Fields</label>
                <div class="lodge-field-checks">
                  {#each schemaFields as fieldName}
                    <label class="lodge-check-label">
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
                <span style="font-size: var(--font-size-xs); color: var(--text-tertiary);">Fields members can edit. Leave empty to allow all fields.</span>
              </div>
              <div class="lodge-field-row" style="flex-direction: column; align-items: flex-start;">
                <label class="lodge-field-label">Read-only Fields</label>
                <div class="lodge-field-checks">
                  {#each schemaFields as fieldName}
                    <label class="lodge-check-label">
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
                <span style="font-size: var(--font-size-xs); color: var(--text-tertiary);">Fields visible to members but not editable.</span>
              </div>
            {/if}
          </div>
        {/if}
      </div>
    </div>
  </div>
{/if}

<style>
  /* ── Back link in breadcrumb ────────────────────── */
  .schema-back-link {
    background: none;
    border: none;
    color: var(--accent);
    font-size: inherit;
    cursor: pointer;
    padding: 0;
  }
  .schema-back-link:hover { text-decoration: underline; }

  /* ── Settings grid ─────────────────────────────── */
  .schema-settings-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: var(--space-md);
  }

  .schema-settings-grid-three {
    grid-template-columns: 1fr 1fr 1fr;
  }

  /* ── Schema field cards (matches CollectionList) ── */
  .schema-field-card {
    border: 1px solid var(--border-primary);
    border-radius: var(--radius-md);
    padding: var(--space-sm) var(--space-md);
    margin-bottom: var(--space-xs);
    background: var(--bg-primary);
    transition: border-color 0.15s;
  }

  .schema-field-card:hover {
    border-color: var(--border-hover, var(--border-primary));
  }

  .schema-field-card-expanded {
    border-color: var(--accent);
  }

  .schema-sortable-ghost {
    opacity: 0.35;
  }

  .schema-field-header {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
  }

  .schema-drag-handle {
    cursor: grab;
    color: var(--text-light);
    display: flex;
    align-items: center;
    padding: 4px 2px;
    opacity: 0.3;
    transition: opacity 0.15s;
    flex-shrink: 0;
  }

  .schema-field-card:hover .schema-drag-handle {
    opacity: 0.6;
  }

  .schema-drag-handle:hover {
    opacity: 1 !important;
  }

  .schema-field-main {
    flex: 1;
    display: flex;
    align-items: center;
    gap: var(--space-sm);
    min-width: 0;
  }

  .schema-field-label {
    flex: 1;
    min-width: 0;
  }

  .schema-required-toggle {
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 24px;
    height: 24px;
    flex-shrink: 0;
  }

  .schema-required-star {
    font-size: 16px;
    font-weight: 700;
    color: var(--text-light);
    transition: color 0.15s;
  }

  .schema-required-star.active {
    color: var(--danger);
  }

  .schema-field-actions {
    display: flex;
    align-items: center;
    gap: 0;
    flex-shrink: 0;
  }

  .schema-gear-active {
    color: var(--accent);
  }

  .schema-field-meta {
    padding-left: 22px;
    margin-top: 2px;
  }

  /* ── Expanded options ─────────────────────────── */
  .schema-field-options {
    margin-top: var(--space-sm);
    padding-top: var(--space-sm);
    border-top: 1px solid var(--border-secondary);
    display: flex;
    flex-direction: column;
    gap: var(--space-sm);
  }

  .schema-opt-row {
    display: flex;
    flex-direction: column;
    gap: 3px;
  }

  .schema-opt-label {
    font-size: 11px;
    font-weight: 600;
    color: var(--text-tertiary);
    text-transform: uppercase;
    letter-spacing: 0.04em;
    margin-bottom: 1px;
  }

  .schema-opt-input {
    font-size: 13px;
  }

  /* ── Pill toggle (Visual / JSON) ──────────────── */
  .schema-pill-toggle {
    display: inline-flex;
    background: var(--bg-hover);
    border: none;
    border-radius: 6px;
    padding: 2px;
    cursor: pointer;
    gap: 0;
  }

  .schema-pill-opt {
    font-size: 11px;
    font-weight: 500;
    padding: 3px 10px;
    border-radius: 4px;
    color: var(--text-tertiary);
    transition: all 0.15s;
  }

  .schema-pill-opt.selected {
    background: var(--bg-primary);
    color: var(--text-primary);
    box-shadow: var(--shadow-sm);
  }

  /* ── Repeater sub-field list ──────────────────── */
  .schema-subfield-list {
    border-left: 2px solid var(--border-primary);
    padding-left: 12px;
    margin-left: 4px;
    display: flex;
    flex-direction: column;
    gap: 6px;
  }

  .schema-subfield-item {
    display: flex;
    flex-direction: column;
    gap: 2px;
  }

  .schema-subfield-row {
    display: flex;
    align-items: center;
    gap: 6px;
  }

  /* ── Lodge section (matches CollectionList) ───── */
  .lodge-section {
    margin-top: var(--space-lg);
    padding: var(--space-md);
    border: 1px solid var(--border-primary);
    border-radius: var(--radius-md);
  }

  .lodge-section-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: var(--space-xs);
  }

  .lodge-section-label {
    font-size: 11px;
    font-weight: 600;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--text-tertiary);
  }

  .lodge-options {
    display: flex;
    flex-direction: column;
    gap: var(--space-sm);
    padding-top: var(--space-sm);
    border-top: 1px solid var(--border-secondary);
  }

  .lodge-toggle-row {
    display: flex;
    flex-direction: column;
    gap: 2px;
  }

  .lodge-toggle-label {
    display: flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
    font-size: var(--font-size-sm);
    color: var(--text-secondary);
    margin: 0;
  }

  .lodge-toggle-desc {
    font-size: var(--font-size-xs);
    color: var(--text-tertiary);
    padding-left: 26px;
  }

  .lodge-field-row {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
    margin-top: var(--space-xs);
  }

  .lodge-field-label {
    font-size: var(--font-size-sm);
    font-weight: 500;
    color: var(--text-secondary);
  }

  .lodge-field-checks {
    display: flex;
    flex-wrap: wrap;
    gap: 4px 14px;
  }

  .lodge-check-label {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: var(--font-size-sm);
    color: var(--text-secondary);
    cursor: pointer;
  }

  /* ── Responsive ────────────────────────────────── */
  @media (max-width: 768px) {
    .schema-settings-grid,
    .schema-settings-grid-three {
      grid-template-columns: 1fr;
    }

    .schema-field-header {
      flex-wrap: wrap;
    }

    .schema-field-main {
      flex-wrap: wrap;
    }
  }
</style>
