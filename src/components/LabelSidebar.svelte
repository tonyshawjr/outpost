<script>
  import { onMount } from 'svelte';
  import { items as itemsApi, labels as labelsApi } from '$lib/api.js';
  import { addToast } from '$lib/stores.js';

  let {
    collectionId,
    collectionSlug,
    activeLabelId = $bindable(null),
    onLabelDrop = () => {},
  } = $props();

  let foldersData = $state([]);
  let totalCount = $state(0);
  let unfiledCount = $state(0);
  let creatingLabel = $state(false);
  let newLabelName = $state('');
  let dragOver = $state(null);
  let loading = $state(true);

  // Which folder to create labels in (first folder by default)
  let targetFolderId = $derived(foldersData.length > 0 ? foldersData[0].id : null);
  let singleFolder = $derived(foldersData.length === 1);

  onMount(() => {
    loadLabels();
  });

  export async function loadLabels() {
    try {
      const data = await itemsApi.labelsWithCounts(collectionSlug);
      foldersData = data.folders || [];
      totalCount = data.total_items || 0;
      unfiledCount = data.unfiled_count || 0;
    } catch (err) {
      // Silent fail — sidebar just won't show counts
    } finally {
      loading = false;
    }
  }

  async function createLabel() {
    if (!newLabelName.trim() || !targetFolderId) return;

    // Support comma-separated names
    const names = newLabelName.split(',').map(n => n.trim()).filter(Boolean);

    for (const name of names) {
      const slug = name.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
      try {
        await labelsApi.create({
          folder_id: targetFolderId,
          name,
          slug,
        });
      } catch (err) {
        addToast(err.message, 'error');
      }
    }

    newLabelName = '';
    creatingLabel = false;
    await loadLabels();
  }

  function onDragOver(e, labelId) {
    e.preventDefault();
    dragOver = labelId;
  }

  function onDragLeave() {
    dragOver = null;
  }

  function onDrop(e, labelId) {
    e.preventDefault();
    dragOver = null;
    if (labelId === null) return;
    const itemId = parseInt(e.dataTransfer.getData('text/plain'));
    if (!itemId) return;
    onLabelDrop(labelId, [itemId]);
  }
</script>

<div class="folder-sidebar">
  <button
    class="folder-item"
    class:active={activeLabelId === null}
    onclick={() => activeLabelId = null}
  >
    <span class="folder-name">All Items</span>
    <span class="folder-count">{totalCount}</span>
  </button>
  <button
    class="folder-item"
    class:active={activeLabelId === 'unfiled'}
    onclick={() => activeLabelId = 'unfiled'}
  >
    <span class="folder-name">Unfiled</span>
    <span class="folder-count">{unfiledCount}</span>
  </button>

  <div class="folder-divider"></div>

  {#if loading}
    <div style="padding: 8px 12px; font-size: 11px; color: var(--text-tertiary);">Loading...</div>
  {:else}
    {#each foldersData as folder (folder.id)}
      {#if !singleFolder}
        <div class="folder-section-title">{folder.name}</div>
      {/if}

      {#each folder.labels as label (label.id)}
        <button
          class="folder-item"
          class:active={activeLabelId === label.id}
          class:drag-over={dragOver === label.id}
          onclick={() => activeLabelId = label.id}
          ondragover={(e) => onDragOver(e, label.id)}
          ondragleave={onDragLeave}
          ondrop={(e) => onDrop(e, label.id)}
        >
          <span class="folder-name">{label.name}</span>
          <span class="folder-count">{label.item_count}</span>
        </button>
      {/each}
    {/each}

    {#if creatingLabel}
      <div class="folder-create-wrapper">
        <div class="folder-item folder-create-input">
          <input
            type="text"
            bind:value={newLabelName}
            placeholder="Label name..."
            class="folder-inline-input"
            onkeydown={(e) => {
              if (e.key === 'Enter') createLabel();
              if (e.key === 'Escape') creatingLabel = false;
            }}
            autofocus
          />
        </div>
        <span class="folder-create-hint">Separate with commas to create multiple</span>
      </div>
    {/if}

    <button class="folder-add-btn" onclick={() => { creatingLabel = true; newLabelName = ''; }}>
      + New Label
    </button>
  {/if}
</div>
