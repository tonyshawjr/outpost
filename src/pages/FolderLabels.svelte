<script>
  import { labels as labelsApi, folders as foldersApi } from '$lib/api.js';
  import { currentFolderId, currentFolderCollectionId, addToast, navigate } from '$lib/stores.js';
  import { slugify } from '$lib/utils.js';
  import Checkbox from '$components/Checkbox.svelte';
  import ColorPicker from '$components/ColorPicker.svelte';

  let folderId = $derived($currentFolderId);
  let collId = $derived($currentFolderCollectionId);

  let folder = $state(null);
  let labelsList = $state([]);
  let loading = $state(true);

  // New label form
  let newName = $state('');
  let newSlug = $state('');
  let newParentId = $state(null);
  let newDescription = $state('');
  let newData = $state({});
  let creating = $state(false);

  let schema = $derived(folder ? (Array.isArray(folder.schema) ? folder.schema : []) : []);

  // Load folder + labels
  $effect(() => {
    if (folderId) {
      loadData();
    }
  });

  async function loadData() {
    loading = true;
    try {
      const [folderRes, labelsRes] = await Promise.all([
        foldersApi.get(folderId),
        labelsApi.list(folderId),
      ]);
      folder = folderRes.folder || folderRes.taxonomy;
      labelsList = labelsRes.labels || [];
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      loading = false;
    }
  }

  function autoSlug() {
    newSlug = slugify(newName);
  }

  async function createLabel() {
    if (!newName.trim() || !newSlug.trim()) return;
    creating = true;
    try {
      await labelsApi.create({
        taxonomy_id: folderId,
        name: newName.trim(),
        slug: newSlug.trim(),
        parent_id: newParentId || null,
        description: newDescription.trim(),
        data: newData,
      });
      addToast('Label created', 'success');
      newName = '';
      newSlug = '';
      newParentId = null;
      newDescription = '';
      newData = {};
      const labelsRes = await labelsApi.list(folderId);
      labelsList = labelsRes.labels || [];
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      creating = false;
    }
  }

  async function deleteLabel(label) {
    if (!confirm(`Delete "${label.name}"? This cannot be undone.`)) return;
    try {
      await labelsApi.delete(label.id);
      addToast('Label deleted', 'success');
      const labelsRes = await labelsApi.list(folderId);
      labelsList = labelsRes.labels || [];
    } catch (err) {
      addToast(err.message, 'error');
    }
  }

  function editLabel(label) {
    navigate('folder-label-edit', {
      folderCollectionId: collId,
      folderId: folderId,
      labelId: label.id,
    });
  }

  function handleKeydown(e) {
    if (e.key === 'Enter') {
      e.preventDefault();
      createLabel();
    }
  }

  let topLevelLabels = $derived(labelsList.filter(t => !t.parent_id));
  let childLabelsMap = $derived(() => {
    const map = {};
    for (const t of labelsList) {
      if (t.parent_id) {
        if (!map[t.parent_id]) map[t.parent_id] = [];
        map[t.parent_id].push(t);
      }
    }
    return map;
  });
</script>

<div class="tt-page">
  {#if loading}
    <div class="loading-overlay"><div class="spinner"></div></div>
  {:else if folder}
    <!-- Header -->
    <div class="page-header">
      <div class="page-header-icon sage">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M20.59 13.41l-7.17 7.17a2 2 0 01-2.83 0L2 12V2h10l8.59 8.59a2 2 0 010 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>
      </div>
      <div class="page-header-content">
        <h1 class="page-title">{folder.name}</h1>
        <p class="page-subtitle">{folder.collection_name} &middot; {folder.type || 'flat'}</p>
      </div>
    </div>

    <div class="tt-layout">
      <!-- Left: Create form -->
      <div class="tt-form">
        <h2 class="tt-form-title">Add New {folder.singular_name || folder.name}</h2>

        <div class="tt-field">
          <label class="tt-label" for="label-name">Name</label>
          <input
            id="label-name"
            class="input"
            type="text"
            bind:value={newName}
            oninput={autoSlug}
            onkeydown={handleKeydown}
            placeholder="Label name"
          />
        </div>

        <div class="tt-field">
          <label class="tt-label" for="label-slug">Slug</label>
          <input
            id="label-slug"
            class="input"
            type="text"
            bind:value={newSlug}
            onkeydown={handleKeydown}
            placeholder="label-slug"
          />
          <p class="tt-hint">URL-friendly version of the name.</p>
        </div>

        {#if folder.type === 'hierarchical' && labelsList.length > 0}
          <div class="tt-field">
            <label class="tt-label" for="label-parent">Parent</label>
            <select id="label-parent" class="input" bind:value={newParentId}>
              <option value={null}>None</option>
              {#each topLevelLabels as t}
                <option value={t.id}>{t.name}</option>
              {/each}
            </select>
          </div>
        {/if}

        <div class="tt-field">
          <label class="tt-label" for="label-desc">Description</label>
          <textarea id="label-desc" class="input" bind:value={newDescription} rows="2" style="height: auto;" placeholder="Optional description"></textarea>
        </div>

        {#each schema as field}
          <div class="tt-field">
            <label class="tt-label">{field.label || field.name}</label>
            {#if field.type === 'textarea'}
              <textarea class="input" bind:value={newData[field.name]} rows="2" style="height: auto;" placeholder={field.placeholder || ''}></textarea>
            {:else if field.type === 'number'}
              <input class="input" type="number" bind:value={newData[field.name]} placeholder={field.placeholder || ''} />
            {:else if field.type === 'select'}
              <select class="input" bind:value={newData[field.name]}>
                <option value="">Select...</option>
                {#each (field.choices || '').split('\n').filter(c => c.trim()) as choice}
                  <option value={choice.trim()}>{choice.trim()}</option>
                {/each}
              </select>
            {:else if field.type === 'toggle'}
              <Checkbox bind:checked={newData[field.name]} />
            {:else if field.type === 'color'}
              <ColorPicker bind:value={newData[field.name]} />
            {:else}
              <input class="input" type="text" bind:value={newData[field.name]} placeholder={field.placeholder || ''} />
            {/if}
            {#if field.description}
              <p class="tt-hint">{field.description}</p>
            {/if}
          </div>
        {/each}

        <button
          class="btn btn-primary"
          onclick={createLabel}
          disabled={!newName.trim() || !newSlug.trim() || creating}
          style="margin-top: 8px;"
        >
          {creating ? 'Adding...' : `Add New ${folder.singular_name || folder.name}`}
        </button>
      </div>

      <!-- Right: Label list -->
      <div class="tt-list">
        {#if labelsList.length === 0}
          <p class="tt-empty">No labels yet. Create one using the form.</p>
        {:else}
          <div class="tt-list-header">
            <span class="tt-col-name">Name</span>
            <span class="tt-col-slug">Slug</span>
            <span class="tt-col-count">Count</span>
          </div>
          {#each topLevelLabels as label (label.id)}
            <div class="tt-row">
              <span class="tt-col-name">
                <span class="tt-term-name">{label.name}</span>
                <span class="tt-row-actions">
                  <button class="tt-action" onclick={() => editLabel(label)}>Edit</button>
                  <button class="tt-action tt-action-danger" onclick={() => deleteLabel(label)}>Delete</button>
                </span>
              </span>
              <span class="tt-col-slug">{label.slug}</span>
              <span class="tt-col-count">{label.item_count ?? 0}</span>
            </div>
            <!-- Children -->
            {#if childLabelsMap()[label.id]}
              {#each childLabelsMap()[label.id] as child (child.id)}
                <div class="tt-row tt-row-child">
                  <span class="tt-col-name">
                    <span class="tt-term-name">&mdash; {child.name}</span>
                    <span class="tt-row-actions">
                      <button class="tt-action" onclick={() => editLabel(child)}>Edit</button>
                      <button class="tt-action tt-action-danger" onclick={() => deleteLabel(child)}>Delete</button>
                    </span>
                  </span>
                  <span class="tt-col-slug">{child.slug}</span>
                  <span class="tt-col-count">{child.item_count ?? 0}</span>
                </div>
              {/each}
            {/if}
          {/each}
        {/if}
      </div>
    </div>
  {/if}
</div>

<style>
  .tt-page {
    max-width: var(--content-width-wide);
  }

  .tt-layout {
    display: grid;
    grid-template-columns: 280px 1fr;
    gap: var(--space-2xl);
    align-items: start;
  }

  /* Form */
  .tt-form-title {
    font-size: 14px;
    font-weight: 600;
    color: var(--text);
    margin-bottom: var(--space-lg);
  }

  .tt-field {
    margin-bottom: var(--space-md);
  }

  .tt-label {
    display: block;
    font-size: 13px;
    font-weight: 500;
    color: var(--sec);
    margin-bottom: 4px;
  }

  .tt-hint {
    font-size: 12px;
    color: var(--dim);
    margin-top: 4px;
  }

  /* List */
  .tt-empty {
    font-size: 14px;
    color: var(--dim);
    padding: var(--space-xl) 0;
  }

  .tt-list-header {
    display: grid;
    grid-template-columns: 1fr 160px 60px;
    gap: var(--space-md);
    padding: 0 0 var(--space-sm);
    font-size: 11px;
    font-weight: 500;
    color: var(--dim);
    border-bottom: 1px solid var(--border);
  }

  .tt-row {
    display: grid;
    grid-template-columns: 1fr 160px 60px;
    gap: var(--space-md);
    align-items: center;
    padding: 8px 0;
    border-bottom: 1px solid var(--border);
  }

  .tt-row:hover .tt-row-actions { opacity: 1; }

  .tt-row-child {
    padding-left: var(--space-lg);
  }

  .tt-col-name {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
    min-width: 0;
  }

  .tt-term-name {
    font-size: 14px;
    font-weight: 500;
    color: var(--text);
  }

  .tt-row-actions {
    display: flex;
    gap: var(--space-sm);
    opacity: 0;
    transition: opacity 0.1s;
    flex-shrink: 0;
  }

  .tt-action {
    font-size: 12px;
    color: var(--purple);
    background: none;
    border: none;
    cursor: pointer;
    padding: 0;
  }

  .tt-action:hover { text-decoration: underline; }

  .tt-action-danger { color: var(--danger); }

  .tt-col-slug {
    font-size: 13px;
    color: var(--dim);
    font-family: var(--font-mono);
  }

  .tt-col-count {
    font-size: 13px;
    color: var(--sec);
    font-variant-numeric: tabular-nums;
    text-align: right;
  }

  @media (max-width: 768px) {
    .tt-layout {
      grid-template-columns: 1fr;
    }

    .tt-list-header {
      display: none;
    }

    .tt-row {
      grid-template-columns: 1fr auto;
    }

    .tt-row-actions {
      opacity: 1;
    }
  }
</style>
