<script>
  import { onMount } from 'svelte';
  import { items as itemsApi, collections as collectionsApi, media as mediaApi, ConflictError } from '$lib/api.js';
  import { currentItemId, currentCollectionSlug, navigate, addToast, editorItem, editorCollection, editorReloadSignal, revisionReloadSignal, isEditor, isAdmin } from '$lib/stores.js';
  import RichTextEditor from '$components/RichTextEditor.svelte';
  import FlexibleField from '$components/FlexibleField.svelte';
  import RelationshipField from '$components/RelationshipField.svelte';
  import MediaPicker from '$components/MediaPicker.svelte';
  import RepeaterField from '$components/RepeaterField.svelte';
  import GalleryField from '$components/GalleryField.svelte';
  import Checkbox from '$components/Checkbox.svelte';
  import ColorPicker from '$components/ColorPicker.svelte';

  let itemId = $derived($currentItemId);
  let collSlug = $derived($currentCollectionSlug);
  let collection = $state(null);
  let item = $state(null);
  let schema = $state({});
  let loading = $state(true);
  let saving = $state(false);
  let dirty = $state(false);
  let lastSaved = $state('');

  // Block editor state
  let title = $state('');
  let blocks = $state([]);
  let metaFields = $state({});
  let mediaPickerKey = $state(null);

  // Convert repeater sub-field array [{name,type}] to flat map {name:{type}}
  function toRepeaterSchema(fields) {
    if (!fields) return '{}';
    const arr = Array.isArray(fields) ? fields : [];
    const map = {};
    arr.forEach(f => { if (f.name) { const s = {...f}; delete s.name; map[f.name] = s; }});
    return JSON.stringify(map);
  }

  // UI state
  let addMenuOpenAt = $state(null);
  let hoveredBlock = $state(null);
  let confirmDeleteId = $state(null);
  let itemVersion = $state(null);
  let conflict = $state(null);

  const RESERVED_FIELDS = ['title', 'body', 'blocks'];

  function genId() {
    return Math.random().toString(36).slice(2, 10);
  }

  function createBlock(type) {
    const base = { id: genId(), type };
    switch (type) {
      case 'text': return { ...base, content: '' };
      case 'image': return { ...base, src: '', alt: '', caption: '' };
      case 'markdown': return { ...base, content: '' };
      case 'html': return { ...base, content: '' };
      case 'divider': return { ...base };
      default: return base;
    }
  }

  const BLOCK_TYPES = [
    { type: 'text', label: 'Text', desc: 'Start writing with rich text', color: '#4A8B72' },
    { type: 'image', label: 'Image', desc: 'Upload or embed an image', color: '#C49A3D' },
    { type: 'markdown', label: 'Markdown', desc: 'Write with markdown syntax', color: '#9B7EB8' },
    { type: 'html', label: 'HTML', desc: 'Insert raw HTML code', color: '#6B8FA3' },
    { type: 'divider', label: 'Divider', desc: 'Separate content sections', color: '#B85C4A' },
  ];

  function blockIcon(type) {
    switch (type) {
      case 'text': return '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="15" y2="12"/><line x1="3" y1="18" x2="18" y2="18"/></svg>';
      case 'image': return '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg>';
      case 'markdown': return '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round"><path d="M14 3v4a1 1 0 001 1h4"/><path d="M17 21H7a2 2 0 01-2-2V5a2 2 0 012-2h7l5 5v11a2 2 0 01-2 2z"/><path d="M10 13l-2 2 2 2"/><path d="M14 13l2 2-2 2"/></svg>';
      case 'html': return '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>';
      case 'divider': return '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round"><line x1="3" y1="12" x2="21" y2="12"/></svg>';
      default: return '';
    }
  }

  onMount(() => {
    loadData();
    // Listen for slug changes from right sidebar
    const unsub = editorItem.subscribe(storeItem => {
      if (storeItem && item && storeItem.id === item.id && storeItem.slug !== item.slug) {
        item = { ...item, slug: storeItem.slug };
        dirty = true;
      }
    });
    // Reload when sidebar triggers a revision restore
    let reloadCount = 0;
    const unsubReload = editorReloadSignal.subscribe(n => {
      if (reloadCount > 0 && n > 0) loadData();
      reloadCount++;
    });
    return () => {
      unsub();
      unsubReload();
      editorItem.set(null);
      editorCollection.set(null);
    };
  });

  async function loadData() {
    loading = true;
    try {
      if (collSlug) {
        const collData = await collectionsApi.list();
        collection = (collData.collections || []).find((c) => c.slug === collSlug);
        if (collection) {
          schema = JSON.parse(collection.schema || '{}');
          editorCollection.set(collection);
        }
      }
      if (collSlug && itemId) {
        const itemsData = await itemsApi.list(collSlug);
        item = (itemsData.items || []).find((i) => i.id === itemId);
        if (item) {
          itemVersion = item.updated_at || null;
          conflict = null;
          editorItem.set(item);
          const d = item.data || {};
          title = d.title || '';
          if (Array.isArray(d.blocks) && d.blocks.length > 0) {
            blocks = d.blocks.map((b) => ({ ...b }));
          } else if (d.body) {
            blocks = [{ id: genId(), type: 'text', content: d.body }];
          } else {
            blocks = [{ id: genId(), type: 'text', content: '' }];
          }
          const meta = {};
          for (const key of Object.keys(schema)) {
            if (!RESERVED_FIELDS.includes(key)) meta[key] = d[key] ?? '';
          }
          metaFields = meta;
        }
      }
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      loading = false;
    }
  }

  function buildData() {
    const d = { title, blocks };
    for (const [key, value] of Object.entries(metaFields)) d[key] = value;
    const bodyParts = blocks.filter((b) => b.type === 'text').map((b) => b.content).filter(Boolean);
    if (bodyParts.length > 0) d.body = bodyParts.join('');
    // Merge sidebar fields (featured_image, excerpt, SEO)
    const storeItem = $editorItem;
    if (storeItem?.data) {
      if (storeItem.data.featured_image) d.featured_image = storeItem.data.featured_image;
      if (storeItem.data.excerpt !== undefined) d.excerpt = storeItem.data.excerpt;
      if (storeItem.data.meta_title !== undefined) d.meta_title = storeItem.data.meta_title;
      if (storeItem.data.meta_description !== undefined) d.meta_description = storeItem.data.meta_description;
    }
    return d;
  }

  function markDirty() { dirty = true; }

  function handleConflict(err, retryFn) {
    if (err instanceof ConflictError) {
      conflict = {
        message: err.message,
        reload: () => { conflict = null; loadData(); },
        force: () => { conflict = null; itemVersion = null; retryFn(); },
      };
    } else {
      addToast(err.message, 'error');
    }
  }

  async function save() {
    if (!item) return;
    saving = true;
    try {
      const storeItem = $editorItem;
      const newData = buildData();
      const payload = { data: newData, slug: item.slug };
      if (itemVersion) payload._version = itemVersion;
      // Include published_at if the sidebar has changed it
      if (storeItem && storeItem.published_at !== item.published_at) {
        payload.published_at = storeItem.published_at;
      }
      // Include scheduled_at / status if scheduling state changed
      if (storeItem && storeItem.scheduled_at !== item.scheduled_at) {
        payload.scheduled_at = storeItem.scheduled_at;
        if (storeItem.status && storeItem.status !== item.status) payload.status = storeItem.status;
      }
      const result = await itemsApi.update(item.id, payload);
      if (result.updated_at) itemVersion = result.updated_at;
      // Merge all saved fields back into item so sidebar effects don't revert local state
      item = {
        ...item,
        slug: payload.slug,
        data: newData,
        ...(payload.published_at !== undefined ? { published_at: payload.published_at } : {}),
        ...(payload.scheduled_at !== undefined ? { scheduled_at: payload.scheduled_at } : {}),
        ...(payload.status !== undefined ? { status: payload.status } : {}),
      };
      dirty = false;
      lastSaved = new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
      conflict = null;
      editorItem.set({ ...item });
      revisionReloadSignal.update(n => n + 1);
      addToast('Saved', 'success');
    } catch (err) {
      handleConflict(err, save);
    } finally {
      saving = false;
    }
  }

  async function publish() {
    if (!item) return;
    saving = true;
    try {
      const newData = buildData();
      const payload = { data: newData, slug: item.slug, status: 'published' };
      if (itemVersion) payload._version = itemVersion;
      const result = await itemsApi.update(item.id, payload);
      if (result.updated_at) itemVersion = result.updated_at;
      const now = new Date().toISOString().slice(0, 19).replace('T', ' ');
      if (result.submitted_for_review) {
        item = { ...item, data: newData, status: 'pending_review' };
        addToast('Submitted for review', 'success');
      } else {
        item = { ...item, data: newData, status: 'published', published_at: item.published_at || now };
        addToast('Published', 'success');
      }
      dirty = false;
      lastSaved = new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
      conflict = null;
      editorItem.set({ ...item });
    } catch (err) {
      handleConflict(err, publish);
    } finally {
      saving = false;
    }
  }

  async function approveItem() {
    if (!item) return;
    saving = true;
    try {
      await itemsApi.approve([item.id]);
      const now = new Date().toISOString().slice(0, 19).replace('T', ' ');
      item = { ...item, status: 'published', published_at: item.published_at || now };
      editorItem.set({ ...item });
      addToast('Approved and published', 'success');
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      saving = false;
    }
  }

  async function rejectItem() {
    if (!item) return;
    saving = true;
    try {
      await itemsApi.reject([item.id]);
      item = { ...item, status: 'draft' };
      editorItem.set({ ...item });
      addToast('Rejected — returned to draft', 'success');
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      saving = false;
    }
  }

  async function unpublish() {
    if (!item) return;
    saving = true;
    try {
      const newData = buildData();
      const payload = { data: newData, slug: item.slug, status: 'draft' };
      if (itemVersion) payload._version = itemVersion;
      const result = await itemsApi.update(item.id, payload);
      if (result.updated_at) itemVersion = result.updated_at;
      item = { ...item, data: newData, status: 'draft' };
      dirty = false;
      conflict = null;
      editorItem.set({ ...item });
      addToast('Reverted to draft', 'success');
    } catch (err) {
      handleConflict(err, unpublish);
    } finally {
      saving = false;
    }
  }

  function goBack() {
    navigate('collection-items', { collectionSlug: collSlug });
  }

  async function deleteItem() {
    if (!item) return;
    const title = item.data?.title || item.slug || 'this item';
    if (!confirm(`Delete "${title}"? This cannot be undone.`)) return;
    try {
      await itemsApi.delete(item.id);
      addToast('Deleted', 'success');
      navigate('collection-items', { collectionSlug: collSlug });
    } catch (err) {
      addToast(err.message, 'error');
    }
  }

  async function preview() {
    if (!item || !collection) return;
    const pattern = collection.url_pattern || `/${collSlug}/{slug}`;
    let url = pattern.replace('{slug}', item.slug);
    // For unpublished items, get a preview token so the router serves the draft
    if (item.status !== 'published') {
      try {
        const res = await itemsApi.previewToken(item.id);
        url += (url.includes('?') ? '&' : '?') + 'preview=' + res.token;
      } catch (err) {
        addToast('Could not generate preview link', 'error');
        return;
      }
    }
    window.open(url, '_blank');
  }

  // Block operations
  function addBlock(type, atIndex) {
    const block = createBlock(type);
    const newBlocks = [...blocks];
    newBlocks.splice(atIndex, 0, block);
    blocks = newBlocks;
    addMenuOpenAt = null;
    markDirty();
  }

  function deleteBlock(id) {
    if (blocks.length <= 1) {
      addToast('Cannot delete the last block', 'warning');
      confirmDeleteId = null;
      return;
    }
    blocks = blocks.filter((b) => b.id !== id);
    confirmDeleteId = null;
    markDirty();
  }

  function updateBlock(id, changes) {
    blocks = blocks.map((b) => (b.id === id ? { ...b, ...changes } : b));
    markDirty();
  }

  function toggleAddMenu(index) {
    addMenuOpenAt = addMenuOpenAt === index ? null : index;
  }

  function handleMetaChange(key, value) {
    metaFields = { ...metaFields, [key]: value };
    markDirty();
  }

  async function handleImageUpload(blockId, file) {
    try {
      const result = await mediaApi.upload(file);
      if (result.media?.path) {
        const p = result.media.path;
        updateBlock(blockId, { src: p.startsWith('/') ? p : '/' + p });
        addToast('Image uploaded', 'success');
      }
    } catch (err) {
      addToast(err.message, 'error');
    }
  }

  function handleImageDrop(blockId, e) {
    e.preventDefault();
    const file = e.dataTransfer?.files?.[0];
    if (file && file.type.startsWith('image/')) handleImageUpload(blockId, file);
  }

  function handleImageSelect(blockId, e) {
    const file = e.target.files?.[0];
    if (file) handleImageUpload(blockId, file);
  }

  function handleCanvasClick(e) {
    if (addMenuOpenAt !== null && !e.target.closest('.add-zone')) addMenuOpenAt = null;
  }

  function handleKeydown(e) {
    if ((e.metaKey || e.ctrlKey) && e.key === 's') {
      e.preventDefault();
      if (!saving) save();
    }
  }

  let metaSchema = $derived(
    Object.entries(schema).filter(([key]) => !RESERVED_FIELDS.includes(key))
  );

  // Evaluate conditional logic for a field definition
  function evaluateConditions(fieldDef) {
    if (!fieldDef.conditions || !Array.isArray(fieldDef.conditions) || fieldDef.conditions.length === 0) return true;
    return fieldDef.conditions.every(cond => {
      if (!cond.field) return true;
      const val = metaFields[cond.field];
      const strVal = val === undefined || val === null ? '' : String(val);
      switch (cond.operator) {
        case '==': return strVal === cond.value || (cond.value === 'true' && (strVal === '1' || strVal === 'true')) || (cond.value === 'false' && (strVal === '0' || strVal === 'false' || strVal === ''));
        case '!=': return strVal !== cond.value;
        case 'not_empty': return strVal !== '' && strVal !== '0' && strVal !== 'false';
        case 'empty': return strVal === '' || strVal === '0' || strVal === 'false';
        default: return true;
      }
    });
  }

  let statusText = $derived.by(() => {
    const s = item?.status === 'published' ? 'Published' : item?.status === 'pending_review' ? 'In Review' : item?.status === 'scheduled' ? 'Scheduled' : 'Draft';
    if (saving) return s + ' - Saving...';
    if (dirty) return s + ' - Unsaved';
    if (lastSaved) return s + ' - Saved';
    return s;
  });

  let collName = $derived(collection?.name || collSlug || 'Back');
  let requiresReview = $derived(!!(collection?.require_review));
  let editorRole = $derived($isEditor);
  let adminRole = $derived($isAdmin);
</script>

<svelte:window onkeydown={handleKeydown} />

{#if loading}
  <div class="ed-loading"><div class="spinner"></div></div>
{:else if item}
  <!-- svelte-ignore a11y_click_events_have_key_events -->
  <!-- svelte-ignore a11y_no_static_element_interactions -->
  <div class="ed" onclick={handleCanvasClick}>

    <!-- ─── Top bar ─── -->
    <header class="ed-topbar">
      <div class="ed-topbar-left">
        <button class="ed-back" onclick={goBack} type="button">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
          <span>{collName}</span>
        </button>
        <span class="ed-status">{statusText}</span>
      </div>
      <div class="ed-topbar-right">
        <button class="ed-btn ed-btn-ghost ed-btn-danger" onclick={deleteItem} type="button" title="Delete this item">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg>
        </button>
        <button class="ed-btn ed-btn-ghost" onclick={preview} type="button">Preview</button>
        {#if item.status === 'published'}
          <button class="ed-btn ed-btn-outline" onclick={unpublish} type="button" disabled={saving}>Unpublish</button>
          <button class="ed-btn ed-btn-primary" onclick={save} type="button" disabled={saving}>
            {saving ? 'Saving...' : 'Update'}
          </button>
        {:else if item.status === 'pending_review' && adminRole}
          <button class="ed-btn ed-btn-outline ed-btn-danger-outline" onclick={rejectItem} type="button" disabled={saving}>Reject</button>
          <button class="ed-btn ed-btn-primary" onclick={approveItem} type="button" disabled={saving}>
            {saving ? 'Approving...' : 'Approve'}
          </button>
        {:else if item.status === 'pending_review'}
          <button class="ed-btn ed-btn-outline" onclick={save} type="button" disabled={saving}>
            {saving ? 'Saving...' : 'Save draft'}
          </button>
          <span style="font-size: 12px; color: var(--dim);">Awaiting review</span>
        {:else}
          <button class="ed-btn ed-btn-outline" onclick={save} type="button" disabled={saving}>
            {saving ? 'Saving...' : 'Save draft'}
          </button>
          <button class="ed-btn ed-btn-primary" onclick={publish} type="button" disabled={saving}>
            {requiresReview && editorRole ? 'Submit for Review' : 'Publish'}
          </button>
        {/if}
      </div>
    </header>

    {#if conflict}
      <div class="ed-conflict-banner">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
        <span class="ed-conflict-msg">{conflict.message}</span>
        <button class="ed-conflict-btn" onclick={conflict.reload}>Reload</button>
        <button class="ed-conflict-btn ed-conflict-btn--force" onclick={conflict.force}>Save anyway</button>
      </div>
    {/if}

    <!-- ─── Canvas ─── -->
    <main class="ed-canvas">
      <div class="ed-content">

        <!-- Title -->
        <input
          class="ed-title"
          type="text"
          bind:value={title}
          oninput={markDirty}
          placeholder="Post title..."
          aria-label="Title"
        />

        <!-- Blocks -->
        <div class="ed-blocks">

          <!-- Add before first block -->
          <div class="add-zone add-zone-edge">
            <button class="add-btn" type="button" onclick={(e) => { e.stopPropagation(); toggleAddMenu(0); }} aria-label="Add block">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            </button>
            {#if addMenuOpenAt === 0}
              <div class="add-dropdown">
                {#each BLOCK_TYPES as bt}
                  <button class="add-dropdown-item" type="button" onclick={(e) => { e.stopPropagation(); addBlock(bt.type, 0); }}>
                    <span class="add-dropdown-icon" style="background: {bt.color};">{@html blockIcon(bt.type)}</span>
                    <span class="add-dropdown-text">
                      <span class="add-dropdown-label">{bt.label}</span>
                      <span class="add-dropdown-desc">{bt.desc}</span>
                    </span>
                  </button>
                {/each}
              </div>
            {/if}
          </div>

          {#each blocks as block, i (block.id)}
            <!-- svelte-ignore a11y_no_static_element_interactions -->
            <div
              class="ed-block"
              class:hovered={hoveredBlock === block.id}
              onmouseenter={() => hoveredBlock = block.id}
              onmouseleave={() => hoveredBlock = null}
            >
              <!-- Delete -->
              {#if hoveredBlock === block.id}
                <div class="block-actions">
                  {#if confirmDeleteId === block.id}
                    <button class="block-delete-yes" type="button" onclick={(e) => { e.stopPropagation(); deleteBlock(block.id); }}>Delete?</button>
                  {:else}
                    <button class="block-delete-x" type="button" onclick={(e) => { e.stopPropagation(); confirmDeleteId = block.id; }} aria-label="Delete block">
                      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    </button>
                  {/if}
                </div>
              {/if}

              <!-- Content -->
              <div class="block-body">
                {#if block.type === 'text'}
                  <div class="block-text">
                    <RichTextEditor
                      content={block.content}
                      onupdate={(html) => updateBlock(block.id, { content: html })}
                      placeholder="Start writing..."
                    />
                  </div>

                {:else if block.type === 'image'}
                  {#if block.src}
                    <div class="block-img-wrap">
                      <img src={block.src} alt={block.alt || ''} class="block-img" />
                      <div class="block-img-fields">
                        <input class="block-img-input" type="text" value={block.alt} placeholder="Alt text..." oninput={(e) => updateBlock(block.id, { alt: e.target.value })} />
                        <input class="block-img-input" type="text" value={block.caption} placeholder="Caption (optional)..." oninput={(e) => updateBlock(block.id, { caption: e.target.value })} />
                        <button class="block-img-replace" type="button" onclick={() => updateBlock(block.id, { src: '', alt: '', caption: '' })}>Replace image</button>
                      </div>
                    </div>
                  {:else}
                    <!-- svelte-ignore a11y_no_static_element_interactions -->
                    <div class="block-img-drop" ondragover={(e) => e.preventDefault()} ondrop={(e) => handleImageDrop(block.id, e)}>
                      <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg>
                      <p>Drag an image here, or <label class="block-img-browse">browse<input type="file" accept="image/*" style="display:none;" onchange={(e) => handleImageSelect(block.id, e)} /></label></p>
                      <input class="block-img-url" type="text" placeholder="or paste a URL and press Enter" onkeydown={(e) => { if (e.key === 'Enter' && e.target.value.trim()) updateBlock(block.id, { src: e.target.value.trim() }); }} />
                    </div>
                  {/if}

                {:else if block.type === 'markdown'}
                  <textarea class="block-code" value={block.content} oninput={(e) => updateBlock(block.id, { content: e.target.value })} placeholder="Write markdown..." rows="6"></textarea>

                {:else if block.type === 'html'}
                  <textarea class="block-code" value={block.content} oninput={(e) => updateBlock(block.id, { content: e.target.value })} placeholder="<div>Write HTML...</div>" rows="6"></textarea>

                {:else if block.type === 'divider'}
                  <hr class="block-hr" />
                {/if}
              </div>

              <!-- Type label on hover -->
              {#if hoveredBlock === block.id && block.type !== 'text'}
                <span class="block-label">{block.type}</span>
              {/if}
            </div>

            <!-- Add between blocks -->
            <div class="add-zone">
              <button class="add-btn" type="button" onclick={(e) => { e.stopPropagation(); toggleAddMenu(i + 1); }} aria-label="Add block">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
              </button>
              {#if addMenuOpenAt === i + 1}
                <div class="add-dropdown">
                  {#each BLOCK_TYPES as bt}
                    <button class="add-dropdown-item" type="button" onclick={(e) => { e.stopPropagation(); addBlock(bt.type, i + 1); }}>
                      <span class="add-dropdown-icon" style="background: {bt.color};">{@html blockIcon(bt.type)}</span>
                      <span class="add-dropdown-text">
                        <span class="add-dropdown-label">{bt.label}</span>
                        <span class="add-dropdown-desc">{bt.desc}</span>
                      </span>
                    </button>
                  {/each}
                </div>
              {/if}
            </div>
          {/each}
        </div>

        <!-- Metadata -->
        {#if metaSchema.length > 0}
          <div class="ed-meta">
            <div class="ed-meta-divider"><span>Metadata</span></div>
            {#each metaSchema as [key, fieldDef]}
              {#if evaluateConditions(fieldDef)}
              <div class="ed-meta-field">
                <label class="ed-meta-label" for="meta-{key}">{fieldDef.label || key}</label>
                {#if fieldDef.type === 'textarea'}
                  <textarea id="meta-{key}" class="ed-meta-input" rows="3" value={metaFields[key] || ''} oninput={(e) => handleMetaChange(key, e.target.value)}></textarea>
                {:else if fieldDef.type === 'richtext'}
                  <RichTextEditor
                    content={metaFields[key] || ''}
                    onupdate={(html) => handleMetaChange(key, html)}
                  />
                {:else if fieldDef.type === 'toggle'}
                  <Checkbox checked={metaFields[key] === '1' || metaFields[key] === true} onchange={() => handleMetaChange(key, metaFields[key] === '1' || metaFields[key] === true ? '0' : '1')} />
                {:else if fieldDef.type === 'number'}
                  <input id="meta-{key}" class="ed-meta-input" type="number" value={metaFields[key] || ''} oninput={(e) => handleMetaChange(key, e.target.value)} />
                {:else if fieldDef.type === 'date'}
                  <input id="meta-{key}" class="ed-meta-input" type="date" value={metaFields[key] || ''} oninput={(e) => handleMetaChange(key, e.target.value)} />
                {:else if fieldDef.type === 'color'}
                  <ColorPicker value={metaFields[key] || '#000000'} onchange={(v) => handleMetaChange(key, v)} />
                {:else if fieldDef.type === 'select'}
                  <select id="meta-{key}" class="ed-meta-input" value={metaFields[key] || ''} onchange={(e) => handleMetaChange(key, e.target.value)}>
                    <option value="">— Select —</option>
                    {#each (fieldDef.options || []) as opt}
                      <option value={typeof opt === 'object' ? opt.value : opt} selected={metaFields[key] === (typeof opt === 'object' ? opt.value : opt)}>{typeof opt === 'object' ? opt.label : opt}</option>
                    {/each}
                  </select>
                {:else if fieldDef.type === 'image'}
                  <div style="display: flex; align-items: center; gap: 8px;">
                    {#if metaFields[key]}
                      <img src={metaFields[key]} alt="" style="width: 48px; height: 48px; object-fit: cover; border-radius: 6px; border: 1px solid #e0e0e0;" />
                    {/if}
                    <input class="ed-meta-input" type="text" value={metaFields[key] || ''} oninput={(e) => handleMetaChange(key, e.target.value)} placeholder="/uploads/..." style="flex: 1;" />
                    <button class="ed-btn ed-btn-outline" type="button" onclick={() => { mediaPickerKey = key; }} style="white-space: nowrap;">Browse</button>
                  </div>
                  {#if mediaPickerKey === key}
                    <MediaPicker
                      onselect={(media) => { handleMetaChange(key, media.path); mediaPickerKey = null; }}
                      onclose={() => { mediaPickerKey = null; }}
                    />
                  {/if}
                {:else if fieldDef.type === 'gallery'}
                  <GalleryField
                    items={JSON.stringify(metaFields[key] || [])}
                    onchange={(items) => handleMetaChange(key, typeof items === 'string' ? JSON.parse(items) : items)}
                  />
                {:else if fieldDef.type === 'repeater'}
                  <RepeaterField
                    schema={toRepeaterSchema(fieldDef.fields)}
                    items={JSON.stringify(metaFields[key] || [])}
                    onchange={(items) => handleMetaChange(key, typeof items === 'string' ? JSON.parse(items) : items)}
                  />
                {:else if fieldDef.type === 'flexible'}
                  <FlexibleField
                    layouts={JSON.stringify(fieldDef.layouts || {})}
                    items={JSON.stringify(metaFields[key] || [])}
                    onchange={(items) => handleMetaChange(key, items)}
                  />
                {:else if fieldDef.type === 'relationship'}
                  <RelationshipField
                    collection={fieldDef.collection || ''}
                    multiple={fieldDef.multiple !== false}
                    max={fieldDef.max || 0}
                    value={JSON.stringify(metaFields[key] || [])}
                    onchange={(val) => handleMetaChange(key, JSON.parse(val))}
                  />
                {:else}
                  <input id="meta-{key}" class="ed-meta-input" type="text" value={metaFields[key] || ''} oninput={(e) => handleMetaChange(key, e.target.value)} />
                {/if}
              </div>
              {/if}
            {/each}
          </div>
        {/if}
      </div>
    </main>
  </div>
{:else}
  <div class="ed">
    <div class="ed-empty">
      <p>Item not found</p>
      <button class="ed-btn ed-btn-outline" onclick={goBack} type="button">Back to collection</button>
    </div>
  </div>
{/if}

<style>
  /* ─── Editor shell (inside content column) ─── */
  .ed {
    min-height: calc(100vh - 52px);
    display: flex;
    flex-direction: column;
    font-family: var(--font);
    color: #111;
  }

  :global(.dark) .ed {
    color: #e5e5e5;
  }

  .ed-loading {
    min-height: 200px;
    display: flex;
    align-items: center;
    justify-content: center;
  }

  /* ─── Top bar ─── */
  .ed-topbar {
    position: sticky;
    top: 0;
    z-index: 100;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 24px;
    height: 52px;
    background: #fff;
    border-bottom: 1px solid #f0f0f0;
  }

  :global(.dark) .ed-topbar {
    background: #1a1a1a;
    border-bottom-color: #2a2a2a;
  }

  .ed-topbar-left {
    display: flex;
    align-items: center;
    gap: 16px;
  }

  .ed-topbar-right {
    display: flex;
    align-items: center;
    gap: 8px;
  }

  .ed-back {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    background: none;
    border: none;
    font-family: var(--font);
    font-size: 13px;
    font-weight: 500;
    color: #333;
    cursor: pointer;
    padding: 4px 8px 4px 4px;
    border-radius: 6px;
    transition: background 0.15s;
  }

  .ed-back:hover { background: #f5f5f5; }
  :global(.dark) .ed-back { color: #ccc; }
  :global(.dark) .ed-back:hover { background: #2a2a2a; }

  .ed-status {
    font-size: 13px;
    color: #999;
  }

  :global(.dark) .ed-status { color: #666; }

  /* Buttons */
  .ed-btn {
    font-family: var(--font);
    font-size: 13px;
    font-weight: 500;
    padding: 6px 16px;
    border-radius: 6px;
    cursor: pointer;
    transition: all 0.15s;
    border: none;
  }

  .ed-btn:disabled { opacity: 0.5; cursor: default; }

  .ed-btn-outline {
    background: none;
    border: 1px solid #e0e0e0;
    color: #333;
  }

  .ed-btn-outline:hover:not(:disabled) { background: #f5f5f5; }
  :global(.dark) .ed-btn-outline { border-color: #3a3a3a; color: #ccc; }
  :global(.dark) .ed-btn-outline:hover:not(:disabled) { background: #2a2a2a; }

  .ed-btn-primary {
    background: #111;
    color: #fff;
  }

  .ed-btn-primary:hover:not(:disabled) { background: #333; }
  :global(.dark) .ed-btn-primary { background: #e5e5e5; color: #111; }
  :global(.dark) .ed-btn-primary:hover:not(:disabled) { background: #fff; }

  .ed-btn-ghost {
    background: none;
    color: #666;
  }
  .ed-btn-ghost:hover:not(:disabled) { background: #f5f5f5; color: #333; }
  :global(.dark) .ed-btn-ghost { color: #888; }
  :global(.dark) .ed-btn-ghost:hover:not(:disabled) { background: #2a2a2a; color: #ccc; }

  .ed-btn-danger { color: #aaa; }
  .ed-btn-danger:hover:not(:disabled) { background: #fef2f2; color: #e53e3e; }
  :global(.dark) .ed-btn-danger { color: #666; }
  :global(.dark) .ed-btn-danger:hover:not(:disabled) { background: #2d1a1a; color: #fc8181; }

  .ed-btn-danger-outline { border-color: #e53e3e; color: #e53e3e; }
  .ed-btn-danger-outline:hover:not(:disabled) { background: #fef2f2; }
  :global(.dark) .ed-btn-danger-outline { border-color: #fc8181; color: #fc8181; }
  :global(.dark) .ed-btn-danger-outline:hover:not(:disabled) { background: #2d1a1a; }

  /* ─── Canvas ─── */
  .ed-canvas {
    flex: 1;
    display: flex;
    justify-content: center;
    padding: 48px 24px 200px;
  }

  .ed-content {
    width: 100%;
    max-width: var(--content-width-narrow);
  }

  /* ─── Title ─── */
  .ed-title {
    display: block;
    width: 100%;
    font-family: var(--font);
    font-size: 42px;
    font-weight: 700;
    line-height: 1.15;
    letter-spacing: -0.02em;
    color: inherit;
    background: none;
    border: none;
    outline: none;
    padding: 0;
    margin-bottom: 24px;
  }

  .ed-title::placeholder { color: #ccc; }
  :global(.dark) .ed-title::placeholder { color: #444; }

  /* ─── Blocks container ─── */
  .ed-blocks {
    display: flex;
    flex-direction: column;
  }

  /* ─── Add zone ─── */
  .add-zone {
    position: relative;
    display: flex;
    align-items: center;
    justify-content: flex-start;
    height: 32px;
    padding-left: 0;
  }

  .add-zone .add-btn {
    opacity: 0;
    transition: opacity 0.15s;
  }

  .add-zone:hover .add-btn,
  .add-zone:focus-within .add-btn,
  .add-zone:has(.add-dropdown) .add-btn {
    opacity: 1;
  }

  .add-zone-edge .add-btn {
    opacity: 0;
  }

  .add-btn {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: none;
    border: 1.5px solid #ddd;
    color: #aaa;
    cursor: pointer;
    transition: all 0.15s;
    flex-shrink: 0;
  }

  .add-btn:hover {
    border-color: #999;
    color: #666;
    background: #fafafa;
  }

  :global(.dark) .add-btn { border-color: #3a3a3a; color: #555; }
  :global(.dark) .add-btn:hover { border-color: #555; color: #999; background: #222; }

  /* ─── Add dropdown ─── */
  .add-dropdown {
    position: absolute;
    top: 100%;
    left: 0;
    z-index: 200;
    background: #fff;
    border: 1px solid #e8e8e8;
    border-radius: 10px;
    box-shadow: 0 8px 30px rgba(0,0,0,0.12);
    padding: 6px;
    display: flex;
    flex-direction: column;
    min-width: 260px;
    animation: dropIn 0.12s ease-out;
  }

  :global(.dark) .add-dropdown {
    background: #222;
    border-color: #333;
    box-shadow: 0 8px 30px rgba(0,0,0,0.4);
  }

  @keyframes dropIn {
    from { opacity: 0; transform: translateY(-4px); }
    to { opacity: 1; transform: translateY(0); }
  }

  .add-dropdown-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 8px 10px;
    border: none;
    background: none;
    border-radius: 8px;
    cursor: pointer;
    transition: background 0.1s;
    text-align: left;
  }

  .add-dropdown-item:hover { background: #f5f5f5; }
  :global(.dark) .add-dropdown-item:hover { background: #2a2a2a; }

  .add-dropdown-icon {
    width: 32px;
    height: 32px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
  }

  .add-dropdown-text {
    display: flex;
    flex-direction: column;
    gap: 1px;
  }

  .add-dropdown-label {
    font-family: var(--font);
    font-size: 13px;
    font-weight: 600;
    color: #111;
  }

  :global(.dark) .add-dropdown-label { color: #e5e5e5; }

  .add-dropdown-desc {
    font-family: var(--font);
    font-size: 12px;
    color: #999;
  }

  :global(.dark) .add-dropdown-desc { color: #666; }

  /* ─── Block ─── */
  .ed-block {
    position: relative;
    padding: 2px 0;
    border-radius: 4px;
    transition: background 0.15s;
  }

  .ed-block.hovered { background: none; }
  :global(.dark) .ed-block.hovered { background: none; }

  .block-body { min-height: 20px; }

  .block-actions {
    position: absolute;
    top: 4px;
    right: 4px;
    z-index: 10;
  }

  .block-delete-x {
    width: 24px;
    height: 24px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: #fff;
    border: 1px solid #e8e8e8;
    border-radius: 6px;
    color: #999;
    cursor: pointer;
    transition: all 0.15s;
  }

  .block-delete-x:hover { color: #e53e3e; border-color: #e53e3e; }
  :global(.dark) .block-delete-x { background: #222; border-color: #3a3a3a; color: #666; }

  .block-delete-yes {
    font-family: var(--font);
    font-size: 11px;
    font-weight: 600;
    padding: 4px 10px;
    border-radius: 6px;
    border: none;
    background: #e53e3e;
    color: #fff;
    cursor: pointer;
  }

  .block-label {
    position: absolute;
    bottom: 4px;
    right: 8px;
    font-family: var(--font-mono, monospace);
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: #ccc;
    pointer-events: none;
  }

  :global(.dark) .block-label { color: #444; }

  /* ─── Text block (TipTap) ─── */
  .block-text :global(.richtext-editor) { border: none; background: none; }
  .block-text :global(.richtext-toolbar) {
    display: none;
    border: none;
    background: none;
    border-radius: 0;
    box-shadow: none;
    padding: 0 0 10px;
    margin-bottom: 0;
    gap: 4px;
  }
  .ed-block:focus-within .block-text :global(.richtext-toolbar) {
    display: flex;
  }
  :global(.dark) .block-text :global(.richtext-toolbar) {
    background: none;
    box-shadow: none;
  }
  .block-text :global(.richtext-toolbar button) {
    width: 34px;
    height: 34px;
    font-size: 15px;
    color: #666;
    background: none;
  }
  .block-text :global(.richtext-toolbar button svg) {
    width: 17px;
    height: 17px;
  }
  .block-text :global(.richtext-toolbar button:hover) {
    color: #111;
    background: none;
  }
  .block-text :global(.richtext-toolbar button.active) {
    color: #111;
    font-weight: 700;
    background: none;
  }
  .block-text :global(.richtext-toolbar .separator) {
    background: #ddd;
    margin: 6px 4px;
  }
  :global(.dark) .block-text :global(.richtext-toolbar button) { color: #777; }
  :global(.dark) .block-text :global(.richtext-toolbar button:hover) { color: #e5e5e5; }
  :global(.dark) .block-text :global(.richtext-toolbar button.active) { color: #fff; }
  :global(.dark) .block-text :global(.richtext-toolbar .separator) { background: #3a3a3a; }
  .block-text :global(.ProseMirror) {
    padding: 0;
    min-height: 1.5em;
    outline: none;
    font-family: var(--font);
    font-size: 18px;
    line-height: 1.8;
    color: inherit;
  }
  .block-text :global(.ProseMirror p.is-editor-empty:first-child::before) { color: #ccc; }
  :global(.dark) .block-text :global(.ProseMirror p.is-editor-empty:first-child::before) { color: #444; }

  /* ─── Image block ─── */
  .block-img-wrap {
    display: flex;
    flex-direction: column;
    gap: 8px;
  }

  .block-img {
    width: 100%;
    height: auto;
    display: block;
    border-radius: 8px;
  }

  .block-img-fields {
    display: flex;
    flex-direction: column;
    gap: 4px;
  }

  .block-img-input {
    font-family: var(--font);
    font-size: 13px;
    padding: 6px 10px;
    border: 1px solid #e8e8e8;
    border-radius: 6px;
    background: #fafafa;
    color: inherit;
    outline: none;
  }

  .block-img-input:focus { border-color: #999; }
  :global(.dark) .block-img-input { background: #222; border-color: #3a3a3a; }

  .block-img-replace {
    align-self: flex-start;
    font-family: var(--font);
    font-size: 12px;
    color: #999;
    background: none;
    border: none;
    cursor: pointer;
    text-decoration: underline;
    text-underline-offset: 2px;
    padding: 0;
  }

  .block-img-replace:hover { color: #666; }

  .block-img-drop {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
    padding: 48px 24px;
    border: 2px dashed #e0e0e0;
    border-radius: 10px;
    color: #bbb;
    font-size: 14px;
    transition: border-color 0.15s;
  }

  .block-img-drop:hover { border-color: #999; }
  :global(.dark) .block-img-drop { border-color: #333; color: #555; }

  .block-img-drop p { margin: 0; }

  .block-img-browse {
    color: #111;
    font-weight: 500;
    cursor: pointer;
    text-decoration: underline;
    text-underline-offset: 2px;
  }

  :global(.dark) .block-img-browse { color: #e5e5e5; }

  .block-img-url {
    font-family: var(--font);
    font-size: 13px;
    padding: 6px 10px;
    border: 1px solid #e8e8e8;
    border-radius: 6px;
    background: #fff;
    color: inherit;
    outline: none;
    width: 260px;
    max-width: 100%;
    text-align: center;
  }

  .block-img-url:focus { border-color: #999; }
  :global(.dark) .block-img-url { background: #222; border-color: #3a3a3a; }

  /* ─── Code textarea (Markdown / HTML) ─── */
  .block-code {
    display: block;
    width: 100%;
    font-family: var(--font-mono, monospace);
    font-size: 13px;
    line-height: 1.6;
    color: inherit;
    background: #fafafa;
    border: 1px solid #e8e8e8;
    border-radius: 8px;
    padding: 12px 16px;
    resize: vertical;
    outline: none;
    tab-size: 2;
  }

  .block-code:focus { border-color: #999; }
  :global(.dark) .block-code { background: #1e1e1e; border-color: #333; }

  /* ─── Divider block ─── */
  .block-hr {
    border: none;
    height: 1px;
    background: #e8e8e8;
    margin: 16px 0;
  }

  :global(.dark) .block-hr { background: #333; }

  /* ─── Metadata ─── */
  .ed-meta {
    margin-top: 64px;
  }

  .ed-meta-divider {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 20px;
  }

  .ed-meta-divider::before,
  .ed-meta-divider::after {
    content: '';
    flex: 1;
    height: 1px;
    background: #e8e8e8;
  }

  :global(.dark) .ed-meta-divider::before,
  :global(.dark) .ed-meta-divider::after { background: #333; }

  .ed-meta-divider span {
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: #999;
  }

  .ed-meta-field { margin-bottom: 16px; }

  .ed-meta-label {
    display: block;
    font-size: 12px;
    font-weight: 500;
    color: #888;
    margin-bottom: 4px;
    text-transform: capitalize;
  }

  .ed-meta-input {
    display: block;
    width: 100%;
    font-family: var(--font);
    font-size: 14px;
    padding: 8px 12px;
    border: 1px solid #e8e8e8;
    border-radius: 6px;
    background: #fafafa;
    color: inherit;
    outline: none;
  }

  .ed-meta-input:focus { border-color: #999; }
  :global(.dark) .ed-meta-input { background: #1e1e1e; border-color: #333; }

  textarea.ed-meta-input { resize: vertical; }

  /* ─── Empty state ─── */
  .ed-empty {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 12px;
    padding: 64px;
    color: #999;
    font-size: 16px;
  }

  /* ── Mobile ── */
  @media (max-width: 768px) {
    .ed {
      min-height: calc(100vh - 52px - var(--mobile-nav-height) - env(safe-area-inset-bottom, 0px));
      padding-bottom: calc(var(--mobile-nav-height) + env(safe-area-inset-bottom, 0px));
    }

    .ed-topbar {
      padding: 0 12px;
      gap: 8px;
    }

    .ed-topbar-left {
      gap: 8px;
      min-width: 0;
    }

    .ed-topbar-right {
      gap: 4px;
    }

    .ed-topbar-right .ed-btn-ghost,
    .ed-topbar-right .ed-btn-outline {
      font-size: 0;
      padding: 6px 8px;
    }

    .ed-topbar-right .ed-btn-ghost svg,
    .ed-topbar-right .ed-btn-outline svg {
      font-size: initial;
    }

    .ed-history-drawer {
      padding: 0 16px;
    }

    .ed-scroll {
      padding: 24px 16px 200px;
    }

    .ed-title {
      font-size: 28px;
    }

    .add-dropdown {
      min-width: 0;
      width: calc(100vw - 64px);
      max-width: 300px;
    }

    .ed-empty {
      padding: 32px 16px;
    }

    .ed-meta {
      margin-top: 32px;
    }

    .block-img-url {
      width: 100%;
    }
  }

  /* ── Conflict banner ── */
  .ed-conflict-banner {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 16px;
    background: #fffbeb;
    border-bottom: 1px solid #f5e6b8;
    font-size: 13px;
    color: #92400e;
  }
  :global(.dark) .ed-conflict-banner {
    background: #332b10;
    border-color: #4a3f1a;
    color: #fbbf24;
  }
  .ed-conflict-msg { flex: 1; }
  .ed-conflict-btn {
    padding: 4px 12px;
    border-radius: var(--radius-sm);
    font-size: 12px;
    font-weight: 500;
    cursor: pointer;
    border: 1px solid #e5d5a0;
    background: #fff;
    color: #92400e;
    font-family: var(--font);
    white-space: nowrap;
  }
  .ed-conflict-btn:hover { background: #fef3c7; }
  :global(.dark) .ed-conflict-btn {
    background: #4a3f1a;
    border-color: #5c4f22;
    color: #fbbf24;
  }
  :global(.dark) .ed-conflict-btn:hover { background: #5c4f22; }
  .ed-conflict-btn--force {
    background: none;
    border-color: transparent;
    color: #b45309;
    text-decoration: underline;
    text-underline-offset: 2px;
  }
  :global(.dark) .ed-conflict-btn--force {
    background: none;
    border-color: transparent;
    color: #d97706;
  }
</style>
