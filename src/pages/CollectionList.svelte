<script>
  import { onMount } from 'svelte';
  import { collections as collectionsApi, workflows as workflowsApi } from '$lib/api.js';
  import { collectionsList, navigate, addToast } from '$lib/stores.js';
  import { slugify } from '$lib/utils.js';
  import EmptyState from '$components/EmptyState.svelte';
  import ContextualTip from '$components/ContextualTip.svelte';
  import { tips } from '$lib/tips.js';

  let colls = $derived($collectionsList);
  let loading = $state(true);
  let showCreate = $state(false);
  let editingColl = $state(null);
  let availableWorkflows = $state([]);

  // Collection form (shared for create + edit)
  let formName = $state('');
  let formSlug = $state('');
  let formSingularName = $state('');
  let formUrlPattern = $state('');
  let formRequireReview = $state(false);
  let formWorkflowId = $state(null);
  let formSchema = $state([
    { name: 'title', type: 'text', label: 'Title', required: true, placeholder: '', description: '', defaultValue: '', choices: '' },
    { name: 'body', type: 'richtext', label: 'Body', required: false, placeholder: '', description: '', defaultValue: '', choices: '' }
  ]);
  let submitting = $state(false);
  let expandedFields = $state({});

  // Syndication settings
  let formFeedEnabled = $state(false);
  let formSitemapEnabled = $state(true);

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

  // Delete confirmation modal
  let deleteTarget = $state(null);
  let deleteConfirmText = $state('');
  let deleting = $state(false);

  function toggleFieldExpand(i) {
    expandedFields = { ...expandedFields, [i]: !expandedFields[i] };
  }

  function autoFieldName(i) {
    if (!formSchema[i].name || formSchema[i].name === slugifyField(formSchema[i - 1]?.label || '')) {
      formSchema[i].name = slugifyField(formSchema[i].label);
    }
  }

  function slugifyField(text) {
    return text.toLowerCase().replace(/[^a-z0-9_]/g, '_').replace(/_+/g, '_').replace(/^_|_$/g, '');
  }

  onMount(async () => {
    await loadCollections();
    await loadWorkflows();
  });

  async function loadWorkflows() {
    try {
      const data = await workflowsApi.list();
      availableWorkflows = data.workflows || [];
    } catch (e) {}
  }

  async function loadCollections() {
    loading = true;
    try {
      const data = await collectionsApi.list();
      collectionsList.set(data.collections || []);
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      loading = false;
    }
  }

  function autoSlug() {
    if (!editingColl) formSlug = slugify(formName);
  }

  function addSchemaField() {
    formSchema = [...formSchema, { name: '', type: 'text', label: '', required: false, placeholder: '', description: '', defaultValue: '', choices: '' }];
  }

  function removeSchemaField(i) {
    formSchema = formSchema.filter((_, idx) => idx !== i);
  }

  function resetForm() {
    formName = '';
    formSlug = '';
    formSingularName = '';
    formUrlPattern = '';
    formRequireReview = false;
    formWorkflowId = null;
    formSchema = [
      { name: 'title', type: 'text', label: 'Title', required: true, placeholder: '', description: '', defaultValue: '', choices: '' },
      { name: 'body', type: 'richtext', label: 'Body', required: false, placeholder: '', description: '', defaultValue: '', choices: '' }
    ];
    editingColl = null;
    showCreate = false;
    expandedFields = {};
    formFeedEnabled = false;
    formSitemapEnabled = true;
    formLodgeEnabled = false;
    formLodgeConfig = {
      allow_create: true,
      allow_edit: true,
      allow_delete: false,
      require_approval: false,
      max_items_per_member: 0,
      editable_fields: [],
      readonly_fields: [],
    };
  }

  function openCreate() {
    resetForm();
    showCreate = true;
  }

  function openEdit(coll) {
    editingColl = coll;
    formName = coll.name;
    formSlug = coll.slug;
    formSingularName = coll.singular_name || coll.name;
    formUrlPattern = coll.url_pattern || `/${coll.slug}/{slug}`;
    formRequireReview = !!(coll.require_review);
    formWorkflowId = coll.workflow_id || null;
    let schema = {};
    try { schema = JSON.parse(coll.schema || '{}'); } catch { schema = {}; }
    formSchema = Object.entries(schema).map(([name, def]) => ({
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
      repeaterFields: def.fields ? JSON.stringify(def.fields, null, 2) : '',
      conditions: def.conditions || [],
    }));
    if (formSchema.length === 0) formSchema = [{ name: '', type: 'text', label: '', required: false, placeholder: '', description: '', defaultValue: '', choices: '' }];
    formFeedEnabled = !!(coll.feed_enabled);
    formSitemapEnabled = coll.sitemap_enabled !== undefined ? !!(coll.sitemap_enabled) : true;
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
    showCreate = true;
  }

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
    if (!formName || !formSlug) return;
    submitting = true;
    try {
      const schema = buildSchema();
      if (editingColl) {
        await collectionsApi.update(editingColl.id, {
          name: formName,
          singular_name: formSingularName || formName,
          schema,
          url_pattern: formUrlPattern || `/${formSlug}/{slug}`,
          require_review: formRequireReview ? 1 : 0,
          workflow_id: formWorkflowId || null,
          feed_enabled: formFeedEnabled ? 1 : 0,
          sitemap_enabled: formSitemapEnabled ? 1 : 0,
          lodge_enabled: formLodgeEnabled ? 1 : 0,
          lodge_config: formLodgeConfig,
        });
        addToast('Collection updated', 'success');
      } else {
        await collectionsApi.create({
          name: formName,
          slug: formSlug,
          singular_name: formSingularName || formName,
          schema,
          url_pattern: formUrlPattern || `/${formSlug}/{slug}`,
        });
        addToast('Collection created', 'success');
      }
      await loadCollections();
      resetForm();
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      submitting = false;
    }
  }

  function openDeleteConfirm(coll) {
    deleteTarget = coll;
    deleteConfirmText = '';
  }

  function closeDeleteConfirm() {
    deleteTarget = null;
    deleteConfirmText = '';
    deleting = false;
  }

  async function confirmDeleteCollection() {
    if (!deleteTarget || deleteConfirmText !== deleteTarget.name) return;
    deleting = true;
    try {
      await collectionsApi.delete(deleteTarget.id);
      await loadCollections();
      addToast('Collection deleted', 'success');
      closeDeleteConfirm();
    } catch (err) {
      addToast(err.message, 'error');
      deleting = false;
    }
  }

  function viewItems(coll) {
    navigate('collection-items', { collectionSlug: coll.slug });
  }
</script>

<div>
  <div class="page-header">
    <div class="page-header-icon sage">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
    </div>
    <div class="page-header-content">
      <h1 class="page-title">Collections</h1>
      <p class="page-subtitle">Manage your content types and their schemas</p>
    </div>
    <div class="page-header-actions">
      <button class="btn btn-primary" onclick={openCreate}>
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        New Collection
      </button>
    </div>
  </div>

  <ContextualTip tipKey="collections" message={tips.collections} />

  {#if loading}
    <div class="loading-overlay"><div class="spinner"></div></div>
  {:else if colls.length === 0}
    <EmptyState
      icon='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1"><path d="M22 19a2 2 0 01-2 2H4a2 2 0 01-2-2V5a2 2 0 012-2h5l2 3h9a2 2 0 012 2z"/></svg>'
      title="No collections yet"
      description="Collections let you create structured content like blog posts, projects, or team members."
      ctaLabel="Create Your First Collection"
      ctaAction={openCreate}
    />
  {:else}
    <div class="coll-grid">
      {#each colls as coll (coll.id)}
        {@const schema = (() => { try { return JSON.parse(coll.schema || '{}'); } catch { return {}; } })()}
        {@const fieldCount = Object.keys(schema).length}
        <div class="card" style="cursor: pointer;" onclick={() => viewItems(coll)} role="button" tabindex="0" onkeydown={(e) => e.key === 'Enter' && viewItems(coll)}>
          <div style="display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: var(--space-md);">
            <div>
              <div style="font-family: var(--font-serif); font-size: 18px; font-weight: 600;">{coll.name}</div>
              <div style="font-size: var(--font-size-sm); color: var(--text-tertiary); margin-top: 2px;">{coll.url_pattern || `/${coll.slug}/{slug}`}</div>
            </div>
            <span class="badge badge-success" style="font-size: 14px; padding: 4px 12px;">{coll.item_count ?? 0}</span>
          </div>
          <div style="font-size: var(--font-size-sm); color: var(--text-tertiary); margin-bottom: var(--space-md);">
            {fieldCount} field{fieldCount !== 1 ? 's' : ''}: {Object.keys(schema).slice(0, 3).join(', ')}{fieldCount > 3 ? '...' : ''}
          </div>
          <div style="display: flex; gap: var(--space-xs);">
            <button class="btn btn-secondary btn-sm" onclick={(e) => { e.stopPropagation(); navigate('collection-schema', { collectionSlug: coll.slug }); }} style="flex: 1;">Edit Schema</button>
            <button class="btn btn-danger btn-sm" onclick={(e) => { e.stopPropagation(); openDeleteConfirm(coll); }}>Delete</button>
          </div>
        </div>
      {/each}
    </div>
  {/if}
</div>

<!-- Create / Edit Collection Modal -->
{#if showCreate}
  <div class="modal-overlay" onclick={resetForm} role="dialog" tabindex="-1">
    <div class="modal modal-lg" onclick={(e) => e.stopPropagation()} role="document">
      <div class="modal-header">
        <h2 class="modal-title">{editingColl ? 'Edit' : 'New'} Collection</h2>
        <button class="btn btn-ghost btn-sm" onclick={resetForm} aria-label="Close">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
      </div>

      <div class="form-group">
        <label class="form-label" for="coll-name">Name</label>
        <input id="coll-name" class="input" type="text" bind:value={formName} oninput={autoSlug} placeholder="Blog Posts" />
      </div>
      {#if !editingColl}
        <div class="form-group">
          <label class="form-label" for="coll-slug">Slug</label>
          <input id="coll-slug" class="input" type="text" bind:value={formSlug} placeholder="blog-posts" />
          <span style="font-size: var(--font-size-xs); color: var(--text-tertiary);">Template identifier — used in <code style="font-size:11px">collection.{formSlug || 'slug'}</code></span>
        </div>
      {:else}
        <div class="form-group">
          <label class="form-label">Slug</label>
          <div style="font-size: var(--font-size-sm); color: var(--text-tertiary); padding: 6px 0;">
            <code style="font-size: 12px;">{formSlug}</code>
            <span style="margin-left: 6px; font-size: var(--font-size-xs);">Template identifier — cannot be changed after creation</span>
          </div>
        </div>
      {/if}
      <div class="form-group">
        <label class="form-label" for="coll-singular">Singular Name</label>
        <input id="coll-singular" class="input" type="text" bind:value={formSingularName} placeholder="Blog Post" />
        <span style="font-size: var(--font-size-xs); color: var(--text-tertiary);">Used for "Add Blog Post" buttons</span>
      </div>
      <div class="form-group">
        <label class="form-label" for="coll-url">URL Pattern</label>
        <input id="coll-url" class="input" type="text" bind:value={formUrlPattern} placeholder="/{formSlug || 'slug'}/&#123;slug&#125;" />
      </div>

      <div class="form-group" style="display: flex; align-items: center; gap: 8px;">
        <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: var(--font-size-sm); color: var(--text-secondary); margin: 0;">
          <input type="checkbox" bind:checked={formRequireReview} style="accent-color: var(--accent);" />
          Require review before publishing
        </label>
        <span style="font-size: var(--font-size-xs); color: var(--text-tertiary);">Editors must submit for review; admins approve or reject.</span>
      </div>

      {#if availableWorkflows.length > 0}
        <div class="form-group">
          <label class="form-label" for="coll-workflow">Workflow</label>
          <select id="coll-workflow" class="input" value={formWorkflowId || ''} onchange={(e) => { formWorkflowId = e.target.value ? Number(e.target.value) : null; }}>
            <option value="">Default (Simple)</option>
            {#each availableWorkflows as wf}
              <option value={wf.id}>{wf.name} ({wf.stages.length} stages)</option>
            {/each}
          </select>
          <span style="font-size: var(--font-size-xs); color: var(--text-tertiary);">
            Assign a workflow to define custom approval stages for this collection.
            <a href="#" onclick={(e) => { e.preventDefault(); navigate('workflows'); }} style="color: var(--accent);">Manage workflows</a>
          </span>
        </div>
      {/if}

      <!-- Syndication Settings -->
      <div style="margin-top: var(--space-md);">
        <span style="font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-tertiary);">Syndication</span>
        <div style="margin-top: var(--space-xs); display: flex; flex-direction: column; gap: 6px;">
          <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: var(--font-size-sm); color: var(--text-secondary); margin: 0;">
            <input type="checkbox" bind:checked={formFeedEnabled} style="accent-color: var(--accent);" />
            Include in RSS feed
          </label>
          <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: var(--font-size-sm); color: var(--text-secondary); margin: 0;">
            <input type="checkbox" bind:checked={formSitemapEnabled} style="accent-color: var(--accent);" />
            Include in sitemap
          </label>
        </div>
      </div>

      <!-- Lodge Settings -->
      {#if editingColl}
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
      {/if}

      <div class="form-group">
        <label class="form-label">Content Fields</label>
        <p style="font-size: var(--font-size-xs); color: var(--text-tertiary); margin-bottom: var(--space-sm);">
          Define the fields each item in this collection will have.
        </p>
        {#each formSchema as field, i}
          <div class="schema-field-card">
            <div class="schema-field-header">
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
                <button class="btn btn-ghost btn-sm" onclick={() => toggleFieldExpand(i)} aria-label="Expand options" title="Field options">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83 0 2 2 0 010-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"/></svg>
                </button>
                <button class="btn btn-ghost btn-sm" onclick={() => removeSchemaField(i)} aria-label="Remove field">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
              </div>
            </div>
            <div class="schema-field-meta">
              <span style="font-size: 11px; color: var(--text-tertiary); font-family: var(--font-mono);">{field.name || '—'}</span>
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
                      {#each collections.filter(c => c.slug !== (editingColl?.slug || '')) as c}
                        <option value={c.slug}>{c.name}</option>
                      {/each}
                    </select>
                  </div>
                  <div class="schema-opt-row">
                    <label class="schema-opt-label">Allow Multiple</label>
                    <label style="display: flex; align-items: center; gap: 6px; font-size: 13px; color: var(--text-secondary);">
                      <input type="checkbox" bind:checked={field.relMultiple} /> Select more than one item
                    </label>
                  </div>
                  <div class="schema-opt-row">
                    <label class="schema-opt-label">Max Items</label>
                    <input class="input schema-opt-input" type="number" bind:value={field.relMax} placeholder="0 = unlimited" min="0" style="max-width: 120px;" />
                  </div>
                {:else if field.type === 'repeater'}
                  <div class="schema-opt-row">
                    <label class="schema-opt-label">Sub-fields (JSON)</label>
                    <textarea class="input schema-opt-input" bind:value={field.repeaterFields} placeholder={'[{"name": "day", "type": "select", "label": "Day", "options": ["Mon","Tue","Wed"]}]'} rows="6" style="height: auto; font-family: var(--font-mono); font-size: 12px;"></textarea>
                    <p style="font-size: 11px; color: var(--text-tertiary); margin-top: 4px;">Define sub-fields as a JSON array. Each entry needs: name, type, label. For select fields add an options array. Types: text, textarea, select, toggle, number, date, image.</p>
                  </div>
                {:else if field.type === 'flexible'}
                  <div class="schema-opt-row">
                    <label class="schema-opt-label">Layouts (JSON)</label>
                    <textarea class="input schema-opt-input" bind:value={field.flexLayouts} placeholder="Paste layout JSON here..." rows="6" style="height: auto; font-family: var(--font-mono); font-size: 12px;"></textarea>
                    <p style="font-size: 11px; color: var(--text-tertiary); margin-top: 4px;">Define layout types with named sub-fields. Each layout has a label and fields object. Example: hero with title + image, cta with heading + url.</p>
                  </div>
                {/if}
                <!-- Conditional Logic (available for all field types) -->
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
                      }} /> Enable
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
        <button class="btn btn-secondary btn-sm" onclick={addSchemaField} style="margin-top: var(--space-xs);">
          Add Field
        </button>
      </div>

      <div class="modal-footer">
        <button class="btn btn-secondary" onclick={resetForm}>Cancel</button>
        <button class="btn btn-primary" onclick={saveCollection} disabled={!formName || (!editingColl && !formSlug) || submitting}>
          {submitting ? 'Saving...' : (editingColl ? 'Save Changes' : 'Create Collection')}
        </button>
      </div>
    </div>
  </div>
{/if}

<!-- Delete Collection Confirmation Modal -->
{#if deleteTarget}
  <div class="modal-overlay" onclick={closeDeleteConfirm} role="dialog" tabindex="-1">
    <div class="modal" onclick={(e) => e.stopPropagation()} role="document">
      <div class="modal-header">
        <h2 class="modal-title">Delete Collection</h2>
        <button class="btn btn-ghost btn-sm" onclick={closeDeleteConfirm} aria-label="Close">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
      </div>

      <div style="padding: 0 var(--space-lg) var(--space-md);">
        <p style="margin: 0 0 var(--space-md); color: var(--text-secondary); font-size: var(--font-size-sm); line-height: 1.6;">
          This will permanently delete <strong>"{deleteTarget.name}"</strong>
          {#if deleteTarget.item_count > 0}
            and all <strong>{deleteTarget.item_count}</strong> {deleteTarget.item_count === 1 ? 'item' : 'items'} inside it.
          {:else}
            (currently empty).
          {/if}
          This action cannot be undone.
        </p>

        <div class="form-group" style="margin-bottom: 0;">
          <label class="form-label" for="delete-confirm-input">Type <strong>{deleteTarget.name}</strong> to confirm</label>
          <input
            id="delete-confirm-input"
            class="input"
            type="text"
            bind:value={deleteConfirmText}
            placeholder={deleteTarget.name}
            onkeydown={(e) => { if (e.key === 'Enter') confirmDeleteCollection(); }}
          />
        </div>
      </div>

      <div class="modal-footer">
        <button class="btn btn-secondary" onclick={closeDeleteConfirm}>Cancel</button>
        <button
          class="btn btn-danger"
          onclick={confirmDeleteCollection}
          disabled={deleteConfirmText !== deleteTarget.name || deleting}
        >
          {deleting ? 'Deleting...' : 'Delete Collection'}
        </button>
      </div>
    </div>
  </div>
{/if}

<style>
  .schema-field-card {
    border: 1px solid var(--border-primary);
    border-radius: var(--radius-md);
    padding: var(--space-md);
    margin-bottom: var(--space-sm);
    background: var(--bg-tertiary);
  }

  .schema-field-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-sm);
  }

  .schema-field-main {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
    flex: 1;
    min-width: 0;
  }

  .schema-field-label {
    flex: 1;
    min-width: 0;
  }

  .schema-field-actions {
    display: flex;
    gap: 2px;
    flex-shrink: 0;
  }

  .schema-field-meta {
    margin-top: 4px;
    padding-left: 2px;
  }

  .schema-required-toggle {
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    flex-shrink: 0;
  }

  .schema-required-star {
    font-size: 18px;
    font-weight: 700;
    color: var(--text-tertiary);
    opacity: 0.4;
    transition: all 0.15s;
  }

  .schema-required-star.active {
    color: var(--danger);
    opacity: 1;
  }

  .schema-field-options {
    margin-top: var(--space-md);
    padding-top: var(--space-md);
    border-top: 1px solid var(--border-primary);
    display: flex;
    flex-direction: column;
    gap: var(--space-sm);
  }

  .schema-opt-row {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
  }

  .schema-opt-label {
    font-size: 12px;
    font-weight: 500;
    color: var(--text-tertiary);
    min-width: 100px;
    flex-shrink: 0;
  }

  .schema-opt-input {
    flex: 1;
    height: 30px;
    font-size: 13px;
  }

  textarea.schema-opt-input {
    height: auto;
    min-height: 60px;
  }

  .coll-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: var(--space-lg);
  }

  @media (max-width: 768px) {
    .coll-grid {
      grid-template-columns: 1fr;
    }
  }

  .lodge-section {
    margin: var(--space-md) 0;
    padding: var(--space-md);
    border: 1px solid var(--border-primary);
    border-radius: var(--radius-md);
    background: var(--bg-tertiary);
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
    letter-spacing: 0.05em;
    text-transform: uppercase;
    color: var(--text-tertiary);
  }

  .lodge-options {
    display: flex;
    flex-direction: column;
    gap: var(--space-sm);
  }

  .lodge-toggle-row {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
    padding: 4px 0;
  }

  .lodge-toggle-label {
    display: flex;
    align-items: center;
    gap: 6px;
    cursor: pointer;
    font-size: var(--font-size-sm);
    color: var(--text-primary);
    font-weight: 500;
    min-width: 160px;
    margin: 0;
  }

  .lodge-toggle-desc {
    font-size: var(--font-size-xs);
    color: var(--text-tertiary);
  }

  .lodge-field-row {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
    padding: 4px 0;
  }

  .lodge-field-label {
    font-size: 12px;
    font-weight: 500;
    color: var(--text-tertiary);
    min-width: 160px;
    flex-shrink: 0;
  }

  .lodge-field-checks {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-xs) var(--space-md);
    margin-top: 4px;
  }

  .lodge-check-label {
    display: flex;
    align-items: center;
    gap: 4px;
    font-size: 13px;
    color: var(--text-secondary);
    cursor: pointer;
    margin: 0;
  }
</style>
