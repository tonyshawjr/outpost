<script>
  import { folders as foldersApi } from '$lib/api.js';
  import { currentFolderId, addToast, navigate } from '$lib/stores.js';
  import { required, slug as slugRule, validate, hasErrors } from '$lib/validation.js';

  let folderId = $derived($currentFolderId);
  let folder = $state(null);
  let loading = $state(true);
  let saving = $state(false);

  let editName = $state('');
  let editSingularName = $state('');
  let editSlug = $state('');
  let editType = $state('flat');
  let editDescription = $state('');
  let editSchema = $state([]);
  let expandedFields = $state({});
  let errors = $state({});

  function validateField(field) {
    const rules = {
      name: required(editName, 'Name'),
      slug: required(editSlug, 'Slug') || slugRule(editSlug),
    };
    if (field) {
      if (rules[field]) errors = { ...errors, [field]: rules[field] };
      else { const { [field]: _, ...rest } = errors; errors = rest; }
    } else {
      errors = validate(rules);
    }
    return !hasErrors(errors);
  }

  function clearError(field) {
    if (errors[field]) { const { [field]: _, ...rest } = errors; errors = rest; }
  }

  $effect(() => {
    if (folderId) loadFolder();
  });

  async function loadFolder() {
    loading = true;
    try {
      const res = await foldersApi.get(folderId);
      folder = res.folder || res.taxonomy;
      editName = folder.name;
      editSingularName = folder.singular_name || '';
      editSlug = folder.slug;
      editType = folder.type || 'flat';
      editDescription = folder.description || '';
      editSchema = Array.isArray(folder.schema) ? folder.schema.map(f => ({ ...f })) : [];
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      loading = false;
    }
  }

  async function saveFolder() {
    if (!validateField()) return;
    saving = true;
    try {
      await foldersApi.update(folderId, {
        name: editName.trim(),
        singular_name: editSingularName.trim(),
        slug: editSlug.trim(),
        type: editType,
        description: editDescription.trim(),
        schema: editSchema,
      });
      addToast('Folder updated', 'success');
      goBack();
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      saving = false;
    }
  }

  function goBack() {
    navigate('folder-manager');
  }

  // Field builder helpers
  function addField() {
    editSchema = [...editSchema, { name: '', type: 'text', label: '', required: false, placeholder: '', description: '', defaultValue: '', choices: '' }];
  }

  function removeField(i) {
    editSchema = editSchema.filter((_, idx) => idx !== i);
  }

  function toggleFieldExpand(i) {
    expandedFields = { ...expandedFields, [i]: !expandedFields[i] };
  }

  function slugifyField(text) {
    return text.toLowerCase().replace(/[^a-z0-9_]/g, '_').replace(/_+/g, '_').replace(/^_|_$/g, '');
  }

  function autoFieldName(i) {
    editSchema[i].name = slugifyField(editSchema[i].label);
  }

  function handleKeydown(e) {
    if (e.key === 'Enter') {
      e.preventDefault();
      saveFolder();
    }
  }
</script>

<div class="txe-page">
  {#if loading}
    <div class="loading-overlay"><div class="spinner"></div></div>
  {:else if folder}
    <div class="page-header">
      <div class="page-header-icon sage">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M20.59 13.41l-7.17 7.17a2 2 0 01-2.83 0L2 12V2h10l8.59 8.59a2 2 0 010 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>
      </div>
      <div class="page-header-content">
        <h1 class="page-title">Edit: {folder.name}</h1>
        <p class="page-subtitle">{folder.collection_name} &middot; {folder.type || 'flat'}</p>
      </div>
      <div class="page-header-actions">
        <button class="btn btn-ghost" onclick={goBack}>
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
          Back
        </button>
      </div>
    </div>

    <div class="txe-sections">
      <!-- Basic Info -->
      <div class="txe-section">
        <h2 class="txe-section-title">General</h2>
        <div class="txe-field">
          <label class="txe-label" for="tax-name">Name</label>
          <input id="tax-name" class="input" class:input-error={errors.name} type="text" bind:value={editName} onkeydown={handleKeydown} oninput={() => clearError('name')} onblur={() => validateField('name')} />
          {#if errors.name}<span class="field-error">{errors.name}</span>{/if}
        </div>
        <div class="txe-field">
          <label class="txe-label" for="tax-singular">Singular Name</label>
          <input id="tax-singular" class="input" type="text" bind:value={editSingularName} onkeydown={handleKeydown} placeholder="e.g. Category" />
          <p class="txe-hint">Used in "Add New Category" buttons.</p>
        </div>
        <div class="txe-field">
          <label class="txe-label" for="tax-slug">Slug</label>
          <input id="tax-slug" class="input" class:input-error={errors.slug} type="text" bind:value={editSlug} onkeydown={handleKeydown} oninput={() => clearError('slug')} onblur={() => validateField('slug')} />
          {#if errors.slug}<span class="field-error">{errors.slug}</span>{/if}
          {#if !errors.slug}<p class="txe-hint">Changing this may break existing references.</p>{/if}
        </div>
        <div class="txe-field">
          <label class="txe-label" for="tax-desc">Description</label>
          <textarea id="tax-desc" class="input" bind:value={editDescription} rows="2" style="height: auto;"></textarea>
        </div>
        <div class="txe-field">
          <label class="txe-label">Type</label>
          <div class="txe-type-row">
            <button class="txe-type-btn" class:active={editType === 'flat'} onclick={() => editType = 'flat'}>Flat</button>
            <button class="txe-type-btn" class:active={editType === 'hierarchical'} onclick={() => editType = 'hierarchical'}>Hierarchical</button>
          </div>
        </div>
      </div>

      <!-- Custom Fields -->
      <div class="txe-section">
        <h2 class="txe-section-title">Label Fields</h2>
        <p class="txe-section-hint">Define extra fields each label in this folder will have. These appear when creating or editing labels.</p>

        {#each editSchema as field, i}
          <div class="txe-schema-row">
            <div class="txe-schema-main">
              <input class="input txe-schema-label" type="text" bind:value={field.label} oninput={() => autoFieldName(i)} placeholder="Field label" />
              <select class="input txe-schema-type" bind:value={field.type}>
                <option value="text">Text</option>
                <option value="textarea">Textarea</option>
                <option value="richtext">Rich Text</option>
                <option value="image">Image</option>
                <option value="number">Number</option>
                <option value="toggle">Toggle</option>
                <option value="select">Select</option>
                <option value="color">Color</option>
                <option value="link">Link</option>
              </select>
              <label class="txe-required-toggle" title="Required">
                <input type="checkbox" bind:checked={field.required} style="display:none;" />
                <span class="txe-required-star" class:active={field.required}>*</span>
              </label>
            </div>
            <div class="txe-schema-meta">
              <span class="txe-schema-machine">{field.name || '—'}</span>
              <div class="txe-schema-actions">
                <button class="txe-action" onclick={() => toggleFieldExpand(i)}>
                  {expandedFields[i] ? 'Less' : 'Options'}
                </button>
                <button class="txe-action txe-action-danger" onclick={() => removeField(i)}>Remove</button>
              </div>
            </div>
            {#if expandedFields[i]}
              <div class="txe-schema-options">
                <div class="txe-opt-row">
                  <label class="txe-opt-label">Machine Name</label>
                  <input class="input txe-opt-input" type="text" bind:value={field.name} placeholder="auto_generated" />
                </div>
                <div class="txe-opt-row">
                  <label class="txe-opt-label">Placeholder</label>
                  <input class="input txe-opt-input" type="text" bind:value={field.placeholder} placeholder="Placeholder text..." />
                </div>
                <div class="txe-opt-row">
                  <label class="txe-opt-label">Description</label>
                  <input class="input txe-opt-input" type="text" bind:value={field.description} placeholder="Help text shown below field" />
                </div>
                <div class="txe-opt-row">
                  <label class="txe-opt-label">Default Value</label>
                  <input class="input txe-opt-input" type="text" bind:value={field.defaultValue} placeholder="Default value" />
                </div>
                {#if field.type === 'select'}
                  <div class="txe-opt-row">
                    <label class="txe-opt-label">Choices</label>
                    <textarea class="input txe-opt-input" bind:value={field.choices} placeholder="One choice per line" rows="3" style="height: auto;"></textarea>
                  </div>
                {/if}
              </div>
            {/if}
          </div>
        {/each}

        <button class="txe-add-field" onclick={addField}>
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          Add Field
        </button>
      </div>
    </div>

    <div class="txe-footer">
      <button class="btn btn-primary" onclick={saveFolder} disabled={!editName.trim() || !editSlug.trim() || saving}>
        {saving ? 'Saving...' : 'Save Changes'}
      </button>
      <button class="btn btn-ghost" onclick={goBack}>Cancel</button>
    </div>
  {/if}
</div>

<style>
  .txe-page { max-width: var(--content-width-narrow); }

  .txe-sections {
    display: flex;
    flex-direction: column;
    gap: var(--space-2xl);
  }

  .txe-section-title {
    font-size: 14px;
    font-weight: 600;
    color: var(--text-primary);
    margin-bottom: var(--space-lg);
    padding-bottom: var(--space-sm);
    border-bottom: 1px solid var(--border-secondary);
  }

  .txe-section-hint {
    font-size: 13px;
    color: var(--text-tertiary);
    margin-top: calc(-1 * var(--space-sm));
    margin-bottom: var(--space-lg);
  }

  .txe-field { margin-bottom: var(--space-md); }

  .txe-label {
    display: block;
    font-size: 13px;
    font-weight: 500;
    color: var(--text-secondary);
    margin-bottom: 4px;
  }

  .txe-hint {
    font-size: 12px;
    color: var(--text-tertiary);
    margin-top: 4px;
  }

  .txe-type-row {
    display: flex;
    gap: var(--space-lg);
  }

  .txe-type-btn {
    padding: 0;
    font-size: 14px;
    font-weight: 500;
    color: var(--text-tertiary);
    background: none;
    border: none;
    cursor: pointer;
    transition: color 0.1s;
  }

  .txe-type-btn.active { color: var(--text-primary); }

  /* Schema field builder */
  .txe-schema-row {
    padding: var(--space-md) 0;
    border-bottom: 1px solid var(--border-secondary);
  }

  .txe-schema-main {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
  }

  .txe-schema-label { flex: 1; }
  .txe-schema-type { flex: 0 0 120px; }

  .txe-required-toggle { cursor: pointer; }

  .txe-required-star {
    font-size: 16px;
    font-weight: 700;
    color: var(--text-tertiary);
    transition: color 0.1s;
    user-select: none;
  }

  .txe-required-star.active { color: var(--danger); }

  .txe-schema-meta {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-top: 4px;
  }

  .txe-schema-machine {
    font-size: 11px;
    color: var(--text-tertiary);
    font-family: var(--font-mono);
  }

  .txe-schema-actions {
    display: flex;
    gap: var(--space-sm);
  }

  .txe-action {
    font-size: 12px;
    color: var(--text-tertiary);
    background: none;
    border: none;
    cursor: pointer;
    padding: 0;
  }

  .txe-action:hover { color: var(--text-primary); }
  .txe-action-danger:hover { color: var(--danger); }

  .txe-schema-options {
    margin-top: var(--space-md);
    padding-left: var(--space-md);
    display: flex;
    flex-direction: column;
    gap: var(--space-sm);
  }

  .txe-opt-row {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
  }

  .txe-opt-label {
    font-size: 12px;
    color: var(--text-tertiary);
    flex: 0 0 100px;
  }

  .txe-opt-input { flex: 1; height: 30px; font-size: 13px; }

  .txe-add-field {
    display: inline-flex;
    align-items: center;
    gap: var(--space-xs);
    margin-top: var(--space-md);
    font-size: 13px;
    font-weight: 500;
    color: var(--accent);
    background: none;
    border: none;
    cursor: pointer;
    padding: 0;
  }

  .txe-add-field:hover { text-decoration: underline; }

  .txe-footer {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
    margin-top: var(--space-2xl);
    padding-top: var(--space-lg);
    border-top: 1px solid var(--border-secondary);
  }
</style>
