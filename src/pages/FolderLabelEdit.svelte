<script>
  import { labels as labelsApi, folders as foldersApi } from '$lib/api.js';
  import { currentLabelId, currentFolderId, currentFolderCollectionId, addToast, navigate } from '$lib/stores.js';
  import { required, slug as slugRule, validate, hasErrors } from '$lib/validation.js';
  import Checkbox from '$components/Checkbox.svelte';
  import ColorPicker from '$components/ColorPicker.svelte';

  let labelId = $derived($currentLabelId);
  let folderId = $derived($currentFolderId);
  let collId = $derived($currentFolderCollectionId);

  let label = $state(null);
  let folder = $state(null);
  let loading = $state(true);
  let saving = $state(false);

  let editName = $state('');
  let editSlug = $state('');
  let editDescription = $state('');
  let editData = $state({});
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
    if (labelId && folderId) loadData();
  });

  async function loadData() {
    loading = true;
    try {
      const [labelRes, folderRes] = await Promise.all([
        labelsApi.get(labelId),
        foldersApi.get(folderId),
      ]);
      label = labelRes.label || labelRes.term;
      folder = folderRes.folder || folderRes.taxonomy;
      editName = label.name;
      editSlug = label.slug;
      editDescription = label.description || '';
      editData = label.data && typeof label.data === 'object' ? { ...label.data } : {};
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      loading = false;
    }
  }

  let schema = $derived(folder && Array.isArray(folder.schema) ? folder.schema : []);

  async function saveLabel() {
    if (!validateField()) return;
    saving = true;
    try {
      await labelsApi.update(labelId, {
        name: editName.trim(),
        slug: editSlug.trim(),
        description: editDescription.trim(),
        data: editData,
      });
      addToast('Label updated', 'success');
      goBack();
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      saving = false;
    }
  }

  async function deleteLabel() {
    if (!confirm(`Delete "${editName}"? This cannot be undone.`)) return;
    try {
      await labelsApi.delete(labelId);
      addToast('Label deleted', 'success');
      goBack();
    } catch (err) {
      addToast(err.message, 'error');
    }
  }

  function goBack() {
    navigate('folder-labels', {
      folderCollectionId: collId,
      folderId: folderId,
    });
  }

  function handleKeydown(e) {
    if (e.key === 'Enter' && e.target.tagName !== 'TEXTAREA') {
      e.preventDefault();
      saveLabel();
    }
  }
</script>

<div class="te-page">
  {#if loading}
    <div class="loading-overlay"><div class="spinner"></div></div>
  {:else if label}
    <div class="page-header">
      <div class="page-header-icon sage">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M20.59 13.41l-7.17 7.17a2 2 0 01-2.83 0L2 12V2h10l8.59 8.59a2 2 0 010 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>
      </div>
      <div class="page-header-content">
        <h1 class="page-title">Edit: {label.name}</h1>
        <p class="page-subtitle">{label.folder_name || label.taxonomy_name} &middot; {label.item_count ?? 0} item{(label.item_count ?? 0) !== 1 ? 's' : ''}</p>
      </div>
      <div class="page-header-actions">
        <button class="btn btn-ghost" onclick={goBack}>
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
          Back
        </button>
      </div>
    </div>

    <div class="te-form">
      <div class="te-field">
        <label class="te-label" for="edit-name">Name</label>
        <input id="edit-name" class="input" class:input-error={errors.name} type="text" bind:value={editName} onkeydown={handleKeydown} oninput={() => clearError('name')} onblur={() => validateField('name')} />
        {#if errors.name}<span class="field-error">{errors.name}</span>{/if}
      </div>

      <div class="te-field">
        <label class="te-label" for="edit-slug">Slug</label>
        <input id="edit-slug" class="input" class:input-error={errors.slug} type="text" bind:value={editSlug} onkeydown={handleKeydown} oninput={() => clearError('slug')} onblur={() => validateField('slug')} />
        {#if errors.slug}<span class="field-error">{errors.slug}</span>{/if}
        {#if !errors.slug}<p class="te-hint">URL-friendly identifier. Changing this may break existing links.</p>{/if}
      </div>

      <div class="te-field">
        <label class="te-label" for="edit-desc">Description</label>
        <textarea id="edit-desc" class="input" bind:value={editDescription} rows="3" style="height: auto;"></textarea>
      </div>

      <!-- Custom fields from folder schema -->
      {#each schema as field}
        <div class="te-field">
          <label class="te-label">{field.label || field.name}</label>
          {#if field.type === 'textarea'}
            <textarea class="input" bind:value={editData[field.name]} rows="3" style="height: auto;" placeholder={field.placeholder || ''}></textarea>
          {:else if field.type === 'number'}
            <input class="input" type="number" bind:value={editData[field.name]} placeholder={field.placeholder || ''} />
          {:else if field.type === 'select'}
            <select class="input" bind:value={editData[field.name]}>
              <option value="">Select...</option>
              {#each (field.choices || '').split('\n').filter(c => c.trim()) as choice}
                <option value={choice.trim()}>{choice.trim()}</option>
              {/each}
            </select>
          {:else if field.type === 'toggle'}
            <Checkbox bind:checked={editData[field.name]} />
          {:else if field.type === 'color'}
            <ColorPicker bind:value={editData[field.name]} />
          {:else if field.type === 'image'}
            <input class="input" type="text" bind:value={editData[field.name]} placeholder={field.placeholder || 'Image URL'} />
          {:else}
            <input class="input" type="text" bind:value={editData[field.name]} placeholder={field.placeholder || ''} onkeydown={handleKeydown} />
          {/if}
          {#if field.description}
            <p class="te-hint">{field.description}</p>
          {/if}
        </div>
      {/each}

      <div class="te-actions">
        <button class="btn btn-primary" onclick={saveLabel} disabled={!editName.trim() || !editSlug.trim() || saving}>
          {saving ? 'Saving...' : 'Update'}
        </button>
        <button class="btn btn-ghost" onclick={goBack}>Cancel</button>
        <button class="te-delete" onclick={deleteLabel}>Delete</button>
      </div>
    </div>
  {/if}
</div>

<style>
  .te-page { max-width: var(--content-width-narrow); }

  .te-form {
    display: flex;
    flex-direction: column;
    gap: var(--space-xs);
  }

  .te-field { margin-bottom: var(--space-md); }

  .te-label {
    display: block;
    font-size: 13px;
    font-weight: 500;
    color: var(--sec);
    margin-bottom: 4px;
  }

  .te-hint {
    font-size: 12px;
    color: var(--dim);
    margin-top: 4px;
  }

  .te-actions {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
    margin-top: var(--space-md);
  }

  .te-delete {
    margin-left: auto;
    font-size: 13px;
    color: var(--danger);
    background: none;
    border: none;
    cursor: pointer;
    padding: 0;
  }

  .te-delete:hover { text-decoration: underline; }
</style>
