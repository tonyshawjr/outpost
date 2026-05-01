<script>
  import { onMount } from 'svelte';
  import { blocks as blocksApi, pageBlocks, pages, collections as collectionsApi, items as itemsApi, brandSettings as brandSettingsApi } from '$lib/api.js';
  import { currentPageId, navigate, addToast } from '$lib/stores.js';
  import Sortable from 'sortablejs';
  import ColorPicker from '$components/ColorPicker.svelte';
  import {
    Plus, GripVertical, Trash2, X, Eye, Save,
    ChevronLeft, Layout, Type, Image, BarChart3,
    Columns, MessageSquare, HelpCircle, Search,
    Loader2, FileText, MousePointerClick, Shield
  } from 'lucide-svelte';

  let pageId = $derived($currentPageId);

  // Loading states
  let loadingPage = $state(true);
  let loadingBlocks = $state(true);
  let saving = $state(false);
  let publishing = $state(false);

  // Page data
  let pageName = $state('Untitled Page');
  let pageSlug = $state('');
  let pageStatus = $state('draft');
  let pageTemplate = $state('default');
  let editingTitle = $state(false);
  let titleInputEl = $state(null);
  let showTitlePrompt = $state(false);
  let promptTitle = $state('');
  let promptSlug = $state('');
  let promptInputEl = $state(null);
  let pagesCollectionId = $state(null);
  let promptParent = $state('');
  let existingPages = $state([]);

  // Blocks
  let pageBlocksList = $state([]);
  let availableBlocks = $state([]);
  let selectedBlockId = $state(null);
  let hoveredBlockId = $state(null);

  // Block picker
  let pickerOpen = $state(false);
  let pickerSearch = $state('');

  // Preview
  let previewSrcdoc = $state('');
  let loadingPreview = $state(false);
  let brandCss = $state('');

  // SortableJS ref
  let blockListEl = $state(null);
  let sortableInstance = null;

  // Templates
  const templates = [
    { value: 'default', label: 'Default' },
    { value: 'landing', label: 'Landing Page' },
    { value: 'content', label: 'Content Page' },
    { value: 'sidebar', label: 'With Sidebar' },
  ];

  // Category icons
  const categoryIcons = {
    layout: Layout,
    content: Type,
    media: Image,
    data: BarChart3,
    cta: MousePointerClick,
    compliance: Shield,
  };

  // Category labels
  const categoryLabels = {
    layout: 'Layout',
    content: 'Content',
    media: 'Media',
    data: 'Data',
    cta: 'Call to Action',
    compliance: 'Compliance',
  };

  function getBlockIcon(category) {
    return categoryIcons[category] || HelpCircle;
  }

  function generateBlockId() {
    return 'b_' + Math.random().toString(36).substr(2, 9);
  }

  // Selected block
  let selectedBlock = $derived(
    selectedBlockId ? pageBlocksList.find(b => b.id === selectedBlockId) : null
  );

  // Selected block definition from available blocks
  let selectedBlockDef = $derived(
    selectedBlock ? availableBlocks.find(ab => ab.slug === (selectedBlock.type || selectedBlock.slug)) : null
  );

  // Group fields into content and settings
  let contentFields = $derived(
    selectedBlockDef?.fields?.filter(f => f.target !== 'setting') ?? []
  );
  let settingsFields = $derived(
    selectedBlockDef?.fields?.filter(f => f.target === 'setting') ?? []
  );

  // Filtered available blocks for picker
  let filteredBlocks = $derived(() => {
    if (!pickerSearch.trim()) return availableBlocks;
    const q = pickerSearch.toLowerCase();
    return availableBlocks.filter(b =>
      b.name.toLowerCase().includes(q) || (b.description || '').toLowerCase().includes(q)
    );
  });

  // Grouped by category
  let groupedBlocks = $derived(() => {
    const blocks = filteredBlocks();
    const groups = {};
    for (const block of blocks) {
      const cat = block.category || 'content';
      if (!groups[cat]) groups[cat] = [];
      groups[cat].push(block);
    }
    return groups;
  });

  let isNewPage = $derived(pageId === 0 || pageId === null);
  let initialized = $state(false);

  async function initBuilder() {
    // Get pages collection ID
    try {
      const collsData = await collectionsApi.list();
      const pagesColl = (collsData.collections || []).find(c => c.slug === 'pages');
      if (pagesColl) pagesCollectionId = pagesColl.id;
    } catch {}

    if (isNewPage) {
      showTitlePrompt = true;
      loadingPage = false;
      loadingBlocks = false;
      await loadAvailableBlocks();
      try {
        const itemsData = await itemsApi.list('pages');
        existingPages = (itemsData.items || []).map(i => {
          const d = typeof i.data === 'string' ? JSON.parse(i.data) : (i.data || {});
          return { id: i.id, title: d.title || i.slug };
        });
      } catch (err) {
        console.warn('Failed to load pages for parent selector:', err);
      }
      setTimeout(() => { if (promptInputEl) promptInputEl.focus(); }, 100);
      return;
    }

    // Existing page
    showTitlePrompt = false;
    await Promise.allSettled([loadPageData(), loadAvailableBlocks(), loadBrandSettings()]);

    if (pageBlocksList.length === 0) {
      pickerOpen = true;
    }

    refreshPreview();
  }

  onMount(() => { initBuilder(); });

  // Re-init when pageId changes (e.g., after creating a new page)
  $effect(() => {
    if (pageId && pageId !== 0 && initialized) {
      initBuilder();
    }
    if (pageId) initialized = true;
  });

  // Highlight selected block in preview (srcdoc iframe is same-origin)
  $effect(() => {
    if (!previewSrcdoc) return;
    // Wait for iframe to render the new srcdoc
    setTimeout(() => {
      const iframe = document.querySelector('.preview-iframe');
      if (iframe?.contentDocument) {
        iframe.contentDocument.querySelectorAll('.kenii-block').forEach(el => {
          el.classList.toggle('selected', el.dataset.blockId === selectedBlockId);
        });
      }
    }, 100);
  });

  async function loadPageData() {
    loadingPage = true;
    try {
      const data = await pageBlocks.get(pageId);
      pageName = data.title || 'Untitled Page';
      pageStatus = data.status || 'draft';
      pageTemplate = data.template || 'full-width';
      pageBlocksList = data.blocks || [];
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      loadingPage = false;
    }
  }

  async function loadAvailableBlocks() {
    loadingBlocks = true;
    try {
      const data = await blocksApi.list();
      availableBlocks = data.blocks || [];
    } catch (err) {
      addToast('Failed to load blocks', 'error');
    } finally {
      loadingBlocks = false;
    }
  }

  async function refreshPreview() {
    if (!pageId || pageId === 0) return;
    loadingPreview = true;
    try {
      const data = await pageBlocks.render(pageId);
      const html = data.content || data.html || '';
      const css = data.css || '';

      previewSrcdoc = `<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<script src="https://cdn.tailwindcss.com"><\/script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>${brandCss}</style>
<style>${css}</style>
<style>
body { margin: 0; font-family: 'Inter', sans-serif; }
.kenii-block { cursor: pointer; transition: outline .15s; }
.kenii-block:hover { outline: 2px solid rgba(124,58,237,.4); outline-offset: 2px; }
</style>
</head>
<body>${html}</body>
</html>`;
    } catch {
      previewSrcdoc = '';
    } finally {
      loadingPreview = false;
    }
  }

  async function loadBrandSettings() {
    try {
      const data = await brandSettingsApi.get();
      const saved = data.saved || {};
      let css = ':root {';
      if (saved.accent_color) css += `--kenii-primary: ${saved.accent_color};`;
      if (saved.heading_font) css += `--kenii-font-heading: "${saved.heading_font}", sans-serif;`;
      if (saved.body_font) css += `--kenii-font-body: "${saved.body_font}", sans-serif;`;
      css += '}';
      brandCss = css;
    } catch {}
  }

  // SortableJS setup
  $effect(() => {
    if (blockListEl && pageBlocksList.length > 0) {
      if (sortableInstance) sortableInstance.destroy();
      sortableInstance = Sortable.create(blockListEl, {
        animation: 200,
        handle: '.block-drag-handle',
        ghostClass: 'block-ghost',
        chosenClass: 'block-chosen',
        dragClass: 'block-drag',
        onEnd(evt) {
          const { oldIndex, newIndex } = evt;
          if (oldIndex !== newIndex) {
            const updated = [...pageBlocksList];
            const [moved] = updated.splice(oldIndex, 1);
            updated.splice(newIndex, 0, moved);
            pageBlocksList = updated;
            autoSaveAndPreview();
          }
        },
      });
    }

    return () => {
      if (sortableInstance) {
        sortableInstance.destroy();
        sortableInstance = null;
      }
    };
  });

  function selectBlock(id) {
    selectedBlockId = id;
  }

  function deselectBlock() {
    selectedBlockId = null;
  }

  function deleteBlock(id) {
    pageBlocksList = pageBlocksList.filter(b => b.id !== id);
    if (selectedBlockId === id) {
      selectedBlockId = null;
    }
    autoSaveAndPreview();
  }

  // Auto-save and refresh preview when blocks change
  let saveTimer = null;
  async function autoSaveAndPreview() {
    if (!pageId || pageId === 0) return;
    try {
      await pageBlocks.save(pageId, pageBlocksList);
      await refreshPreview();
    } catch {}
  }

  function debouncedAutoSave() {
    if (saveTimer) clearTimeout(saveTimer);
    saveTimer = setTimeout(autoSaveAndPreview, 400);
  }

  function addBlock(blockDef) {
    const defaultData = {};
    const defaultSettings = {};
    if (blockDef.fields) {
      for (const field of blockDef.fields) {
        if (field.target === 'setting') {
          defaultSettings[field.key] = field.default ?? '';
        } else {
          defaultData[field.key] = field.default ?? '';
        }
      }
    }

    const newBlock = {
      id: generateBlockId(),
      type: blockDef.slug,
      name: blockDef.name,
      category: blockDef.category || 'content',
      data: defaultData,
      settings: defaultSettings,
    };

    pageBlocksList = [...pageBlocksList, newBlock];
    pickerOpen = false;
    pickerSearch = '';
    selectedBlockId = newBlock.id;
    autoSaveAndPreview();
  }

  function getFieldValue(block, field) {
    if (field.target === 'setting') {
      return block.settings?.[field.key] ?? field.default ?? '';
    }
    return block.data?.[field.key] ?? field.default ?? '';
  }

  function updateBlockField(blockId, fieldKey, value, isSetting = false) {
    pageBlocksList = pageBlocksList.map(b => {
      if (b.id === blockId) {
        if (isSetting) {
          return { ...b, settings: { ...b.settings, [fieldKey]: value } };
        }
        return { ...b, data: { ...b.data, [fieldKey]: value } };
      }
      return b;
    });
    debouncedAutoSave();
  }

  async function handleSave() {
    saving = true;
    try {
      await pageBlocks.save(pageId, pageBlocksList);
      // Save page title and template via collection items API
      if (pageName) {
        const itemData = { title: pageName };
        if (pageTemplate && pageTemplate !== 'default') itemData.template = pageTemplate;
        await itemsApi.update(pageId, itemData);
      }
      addToast('Page saved');
      refreshPreview();
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      saving = false;
    }
  }

  async function handlePublish() {
    publishing = true;
    try {
      await pageBlocks.save(pageId, pageBlocksList);
      await itemsApi.update(pageId, { status: 'published' });
      pageStatus = 'published';
      addToast('Page published');
      refreshPreview();
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      publishing = false;
    }
  }

  function generateSlug(title) {
    return title.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
  }

  async function handleTitlePromptSubmit() {
    if (!promptTitle.trim()) return;
    pageName = promptTitle.trim();
    pageSlug = promptSlug.trim() || generateSlug(pageName);

    if (isNewPage) {
      // Create the page now
      try {
        const pageData = { title: pageName };
        if (promptParent) pageData.parent_id = Number(promptParent);
        const result = await itemsApi.create({
          collection_id: pagesCollectionId,
          slug: pageSlug,
          data: pageData,
          status: 'draft',
        });
        const newId = result.item?.id || result.id;
        if (newId) {
          // Update the pageId store and navigate to the real page
          navigate('page-builder', { pageId: newId });
          showTitlePrompt = false;
          return;
        }
      } catch (err) {
        addToast(err.message || 'Failed to create page', 'error');
        return;
      }
    } else {
      try {
        await itemsApi.update(pageId, { title: pageName, slug: pageSlug });
      } catch {}
    }

    showTitlePrompt = false;
    if (pageBlocksList.length === 0) {
      pickerOpen = true;
    }
  }

  function goBack() {
    navigate('pages');
  }

  function startEditTitle() {
    editingTitle = true;
    requestAnimationFrame(() => {
      if (titleInputEl) {
        titleInputEl.focus();
        titleInputEl.select();
      }
    });
  }

  function finishEditTitle() {
    editingTitle = false;
    if (!pageName.trim()) pageName = 'Untitled Page';
  }

  function handleTitleKeydown(e) {
    if (e.key === 'Enter') finishEditTitle();
    if (e.key === 'Escape') {
      editingTitle = false;
    }
  }
</script>

<svelte:window onkeydown={(e) => {
  if ((e.metaKey || e.ctrlKey) && e.key === 's') {
    e.preventDefault();
    handleSave();
  }
}} />

{#if showTitlePrompt}
<div class="title-prompt-overlay">
  <div class="title-prompt">
    <h1 class="title-prompt-heading">Create a new page</h1>
    <p class="title-prompt-sub">Give your page a name to get started.</p>
    <div class="title-prompt-field">
      <label class="field-label">Page title</label>
      <input
        class="input"
        type="text"
        bind:value={promptTitle}
        bind:this={promptInputEl}
        placeholder="e.g. About Us, Financial Aid, Fall Open House"
        onkeydown={(e) => { if (e.key === 'Enter') handleTitlePromptSubmit(); }}
      />
    </div>
    <div class="title-prompt-field">
      <label class="field-label">URL slug</label>
      <input
        class="input"
        type="text"
        bind:value={promptSlug}
        placeholder={promptTitle ? generateSlug(promptTitle) : 'auto-generated-from-title'}
      />
    </div>
    {#if existingPages.length > 0}
    <div class="title-prompt-field">
      <label class="field-label">Parent page</label>
      <select class="input" bind:value={promptParent}>
        <option value="">None (top-level page)</option>
        {#each existingPages as p}
          <option value={p.id}>{p.title}</option>
        {/each}
      </select>
    </div>
    {/if}
    <div class="title-prompt-actions">
      <button class="btn btn-primary" onclick={handleTitlePromptSubmit} disabled={!promptTitle.trim()}>Continue</button>
    </div>
    <button class="title-prompt-cancel" onclick={() => navigate('pages')}>Cancel and go back to Pages</button>
  </div>
</div>
{/if}

{#if !showTitlePrompt}
<div class="page-builder">
  <!-- ===== LEFT PANEL ===== -->
  <div class="left-panel">
    <!-- Header -->
    <div class="left-header">
      <div class="left-header-top">
        <button class="back-btn" onclick={goBack} title="Back to pages">
          <ChevronLeft size={20} />
        </button>
        <div class="header-actions">
          <button class="action-btn save-btn" onclick={handleSave} disabled={saving}>
            {#if saving}
              <Loader2 size={16} class="spin" />
            {:else}
              <Save size={16} />
            {/if}
            Save
          </button>
          <button class="action-btn publish-btn" onclick={handlePublish} disabled={publishing}>
            {#if publishing}
              <Loader2 size={16} class="spin" />
            {:else}
              <Eye size={16} />
            {/if}
            Publish
          </button>
        </div>
      </div>

      <!-- Page title -->
      <div class="page-title-row">
        {#if editingTitle}
          <input
            bind:this={titleInputEl}
            class="page-title-input"
            bind:value={pageName}
            onblur={finishEditTitle}
            onkeydown={handleTitleKeydown}
          />
        {:else}
          <button class="page-title" onclick={startEditTitle} title="Click to edit">
            {pageName}
          </button>
        {/if}
        <span class="status-badge" class:published={pageStatus === 'published'} class:draft={pageStatus === 'draft'}>
          {pageStatus === 'published' ? 'Published' : 'Draft'}
        </span>
      </div>

      <!-- Template selector -->
      <select class="template-select" bind:value={pageTemplate}>
        {#each templates as tmpl}
          <option value={tmpl.value}>{tmpl.label}</option>
        {/each}
      </select>
    </div>

    <!-- Block List -->
    <div class="block-list-area">
      {#if loadingPage}
        <div class="loading-state">
          <div class="skeleton-block"></div>
          <div class="skeleton-block"></div>
          <div class="skeleton-block"></div>
        </div>
      {:else if pageBlocksList.length === 0 && !pickerOpen}
        <div class="empty-state">
          <div class="empty-icon">
            <Plus size={32} />
          </div>
          <p class="empty-title">Add your first block</p>
          <p class="empty-desc">Start building your page by adding content blocks.</p>
          <button class="add-block-btn" onclick={() => pickerOpen = true}>
            <Plus size={18} />
            Add Block
          </button>
        </div>
      {:else}
        <div class="block-list" bind:this={blockListEl}>
          {#each pageBlocksList as block (block.id)}
            <div
              class="block-item"
              class:active={selectedBlockId === block.id}
              class:hovered={hoveredBlockId === block.id}
              onclick={() => selectBlock(block.id)}
              onmouseenter={() => hoveredBlockId = block.id}
              onmouseleave={() => hoveredBlockId = null}
              data-id={block.id}
            >
              <div class="block-drag-handle" title="Drag to reorder">
                <GripVertical size={16} />
              </div>
              <div class="block-icon">
                <svelte:component this={getBlockIcon(block.category)} size={16} />
              </div>
              <span class="block-name">{block.name}</span>
              <button
                class="block-delete"
                onclick={(e) => { e.stopPropagation(); deleteBlock(block.id); }}
                title="Remove block"
              >
                <Trash2 size={14} />
              </button>
            </div>
          {/each}
        </div>

        <!-- Add block button -->
        <div class="add-block-footer">
          <button class="add-block-btn" onclick={() => pickerOpen = true}>
            <Plus size={18} />
            Add Block
          </button>
        </div>
      {/if}
    </div>

    <!-- Block Picker Drawer -->
    {#if pickerOpen}
      <div class="picker-overlay" onclick={() => { pickerOpen = false; pickerSearch = ''; }}></div>
      <div class="picker-drawer">
        <div class="picker-header">
          <h3 class="picker-title">Add Block</h3>
          <button class="picker-close" onclick={() => { pickerOpen = false; pickerSearch = ''; }}>
            <X size={18} />
          </button>
        </div>

        <div class="picker-search">
          <Search size={16} />
          <input
            class="picker-search-input"
            placeholder="Search blocks..."
            bind:value={pickerSearch}
          />
        </div>

        <div class="picker-body">
          {#if loadingBlocks}
            <div class="loading-state">
              <div class="skeleton-card"></div>
              <div class="skeleton-card"></div>
              <div class="skeleton-card"></div>
              <div class="skeleton-card"></div>
            </div>
          {:else}
            {#each Object.entries(groupedBlocks()) as [category, blocks]}
              <div class="picker-category">
                <h4 class="picker-category-title">{categoryLabels[category] || category}</h4>
                <div class="picker-grid">
                  {#each blocks as block}
                    <button class="picker-card" onclick={() => addBlock(block)}>
                      <div class="picker-card-icon">
                        <svelte:component this={getBlockIcon(block.category)} size={20} />
                      </div>
                      <div class="picker-card-info">
                        <span class="picker-card-name">{block.name}</span>
                        {#if block.description}
                          <span class="picker-card-desc">{block.description}</span>
                        {/if}
                      </div>
                    </button>
                  {/each}
                </div>
              </div>
            {/each}
            {#if Object.keys(groupedBlocks()).length === 0}
              <div class="picker-empty">
                <p>No blocks found</p>
              </div>
            {/if}
          {/if}
        </div>
      </div>
    {/if}
  </div>

  <!-- ===== CENTER PANEL — PREVIEW ===== -->
  <div class="center-panel">
    {#if loadingPreview && pageBlocksList.length > 0}
      <div class="preview-loading">
        <Loader2 size={24} class="spin" />
        <span>Loading preview...</span>
      </div>
    {:else if pageBlocksList.length === 0 && !previewSrcdoc}
      <div class="preview-empty">
        <Layout size={48} strokeWidth={1} />
        <p>Your page preview will appear here</p>
        <p class="preview-empty-sub">Add blocks to start building your page.</p>
      </div>
    {:else}
      <div class="preview-container">
        <iframe
          class="preview-iframe"
          srcdoc={previewSrcdoc}
          title="Page preview"
          onload={(e) => {
            const doc = e.target.contentDocument;
            if (doc) {
              doc.addEventListener('click', (ev) => {
                const blockEl = ev.target.closest('[data-block-id]');
                if (blockEl) selectedBlockId = blockEl.dataset.blockId;
              });
            }
          }}
        ></iframe>
      </div>
    {/if}
  </div>

  <!-- ===== RIGHT PANEL — BLOCK EDITOR ===== -->
  <div class="right-panel" class:open={selectedBlock !== null}>
    {#if selectedBlock && selectedBlockDef}
      <div class="right-header">
        <h3 class="right-title">{selectedBlock.name}</h3>
        <button class="right-close" onclick={deselectBlock}>
          <X size={18} />
        </button>
      </div>

      <div class="right-body">
        <!-- Content fields -->
        {#each contentFields as field}
          <div class="field-group">
            <label class="field-label">{field.label || field.key}</label>

            {#if field.type === 'text'}
              <input
                class="input field-input"
                type="text"
                value={selectedBlock.data[field.key] ?? ''}
                oninput={(e) => updateBlockField(selectedBlock.id, field.key, e.target.value)}
                placeholder={field.placeholder || ''}
              />
            {:else if field.type === 'richtext'}
              <textarea
                class="input field-textarea"
                value={selectedBlock.data[field.key] ?? ''}
                oninput={(e) => updateBlockField(selectedBlock.id, field.key, e.target.value)}
                placeholder={field.placeholder || ''}
              ></textarea>
            {:else if field.type === 'color'}
              <ColorPicker
                value={selectedBlock.data[field.key] || '#7C3AED'}
                onchange={(val) => updateBlockField(selectedBlock.id, field.key, val)}
              />
            {:else if field.type === 'image'}
              <div class="image-upload-placeholder">
                <Image size={20} />
                <span>Upload image</span>
              </div>
            {:else if field.type === 'select'}
              <select
                class="input field-select"
                value={selectedBlock.data[field.key] ?? ''}
                onchange={(e) => updateBlockField(selectedBlock.id, field.key, e.target.value)}
              >
                {#each (field.options || []) as opt}
                  <option value={opt}>{opt}</option>
                {/each}
              </select>
            {:else if field.type === 'link'}
              <div class="link-fields">
                <input
                  class="input field-input"
                  type="url"
                  value={selectedBlock.data[field.key]?.url ?? ''}
                  oninput={(e) => updateBlockField(selectedBlock.id, field.key, { ...selectedBlock.data[field.key], url: e.target.value })}
                  placeholder="URL"
                />
                <input
                  class="input field-input"
                  type="text"
                  value={selectedBlock.data[field.key]?.text ?? ''}
                  oninput={(e) => updateBlockField(selectedBlock.id, field.key, { ...selectedBlock.data[field.key], text: e.target.value })}
                  placeholder="Link text"
                />
              </div>
            {:else}
              <input
                class="input field-input"
                type="text"
                value={selectedBlock.data[field.key] ?? ''}
                oninput={(e) => updateBlockField(selectedBlock.id, field.key, e.target.value)}
              />
            {/if}
          </div>
        {/each}

        <!-- Settings fields with divider -->
        {#if settingsFields.length > 0}
          <div class="settings-divider">
            <span class="settings-divider-label">Settings</span>
          </div>

          {#each settingsFields as field}
            <div class="field-group">
              <label class="field-label">{field.label || field.key}</label>

              {#if field.type === 'text'}
                <input
                  class="input field-input"
                  type="text"
                  value={selectedBlock.settings?.[field.key] ?? field.default ?? ''}
                  oninput={(e) => updateBlockField(selectedBlock.id, field.key, e.target.value, true)}
                  placeholder={field.placeholder || ''}
                />
              {:else if field.type === 'color'}
                <ColorPicker
                  value={selectedBlock.settings?.[field.key] || field.default || '#7C3AED'}
                  onchange={(val) => updateBlockField(selectedBlock.id, field.key, val, true)}
                />
              {:else if field.type === 'select'}
                <select
                  class="input field-select"
                  value={selectedBlock.settings?.[field.key] ?? field.default ?? ''}
                  onchange={(e) => updateBlockField(selectedBlock.id, field.key, e.target.value, true)}
                >
                  {#each (field.options || []) as opt}
                    <option value={opt}>{opt}</option>
                  {/each}
                </select>
              {:else}
                <input
                  class="input field-input"
                  type="text"
                  value={selectedBlock.settings?.[field.key] ?? field.default ?? ''}
                  oninput={(e) => updateBlockField(selectedBlock.id, field.key, e.target.value, true)}
                />
              {/if}
            </div>
          {/each}
        {/if}
      </div>
    {/if}
  </div>
</div>
{/if}

<style>
  /* ===== FULL-SCREEN LAYOUT ===== */
  /* Title prompt overlay */
  .title-prompt-overlay {
    position: fixed;
    inset: 0;
    z-index: 300;
    background: var(--bg);
    display: flex;
    align-items: center;
    justify-content: center;
  }
  .title-prompt {
    max-width: 480px;
    width: 100%;
    padding: 0 24px;
    text-align: center;
  }
  .title-prompt-heading {
    font-size: 32px;
    font-weight: 700;
    letter-spacing: -.03em;
    margin-bottom: 8px;
    color: var(--text);
  }
  .title-prompt-sub {
    font-size: 17px;
    color: var(--sec);
    margin-bottom: 40px;
    line-height: 1.4;
  }
  .title-prompt-field {
    text-align: left;
  }
  .title-prompt-field {
    margin-bottom: 24px;
  }
  .title-prompt-actions {
    display: flex;
    gap: 12px;
    margin-top: 32px;
  }
  .title-prompt-actions .btn {
    width: 100%;
    height: 52px;
    font-size: 16px;
  }
  .title-prompt-cancel {
    display: block;
    margin: 20px auto 0;
    background: none;
    border: none;
    color: var(--dim);
    font-size: 14px;
    font-family: var(--font);
    cursor: pointer;
    transition: color .15s;
  }
  .title-prompt-cancel:hover {
    color: var(--sec);
  }

  .page-builder {
    position: fixed;
    inset: 0;
    z-index: 200;
    display: flex;
    flex-direction: row;
    background: var(--bg);
    color: var(--text);
    font-family: var(--font);
  }

  /* ===== LEFT PANEL ===== */
  .left-panel {
    width: 300px;
    min-width: 300px;
    background: var(--bg);
    border-right: 1px solid var(--border);
    display: flex;
    flex-direction: column;
    position: relative;
    overflow: hidden;
  }

  .left-header {
    padding: 16px;
    border-bottom: 1px solid var(--border);
    flex-shrink: 0;
  }

  .left-header-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 12px;
  }

  .back-btn {
    width: 36px;
    height: 36px;
    border: none;
    background: transparent;
    color: var(--sec);
    border-radius: 8px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all .15s;
  }
  .back-btn:hover {
    background: var(--hover);
    color: var(--text);
  }

  .header-actions {
    display: flex;
    gap: 8px;
  }

  .action-btn {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 8px 14px;
    border: none;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: all .15s;
    font-family: var(--font);
  }
  .action-btn:disabled {
    opacity: .6;
    cursor: not-allowed;
  }

  .save-btn {
    background: var(--hover);
    color: var(--text);
  }
  .save-btn:hover:not(:disabled) {
    background: var(--border);
  }

  .publish-btn {
    background: var(--purple);
    color: #fff;
  }
  .publish-btn:hover:not(:disabled) {
    background: var(--purple-soft, #6D28D9);
  }

  .page-title-row {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 10px;
  }

  .page-title {
    font-size: 17px;
    font-weight: 700;
    color: var(--text);
    background: none;
    border: none;
    padding: 4px 0;
    cursor: pointer;
    text-align: left;
    border-bottom: 1px dashed transparent;
    transition: border-color .15s;
    font-family: var(--font);
    flex: 1;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
  .page-title:hover {
    border-bottom-color: var(--dim);
  }

  .page-title-input {
    font-size: 17px;
    font-weight: 700;
    color: var(--text);
    background: transparent;
    border: none;
    border-bottom: 2px solid var(--purple);
    padding: 4px 0;
    outline: none;
    font-family: var(--font);
    flex: 1;
    min-width: 0;
  }

  .status-badge {
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .04em;
    padding: 3px 8px;
    border-radius: 6px;
    flex-shrink: 0;
  }
  .status-badge.draft {
    background: var(--amber-bg);
    color: var(--amber);
  }
  .status-badge.published {
    background: var(--green-bg);
    color: var(--green);
  }

  .template-select {
    width: 100%;
    padding: 8px 12px;
    background: var(--raised);
    border: 1px solid var(--border);
    border-radius: 8px;
    color: var(--text);
    font-size: 13px;
    font-family: var(--font);
    cursor: pointer;
    outline: none;
    appearance: none;
    -webkit-appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg width='10' height='6' viewBox='0 0 10 6' fill='none' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M1 1L5 5L9 1' stroke='%236B7280' stroke-width='1.5' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 12px center;
    padding-right: 32px;
  }
  .template-select:focus {
    border-color: var(--purple);
    box-shadow: 0 0 0 3px var(--purple-bg);
  }

  /* ===== BLOCK LIST ===== */
  .block-list-area {
    flex: 1;
    overflow-y: auto;
    display: flex;
    flex-direction: column;
  }

  .block-list {
    flex: 1;
    padding: 8px;
  }

  .block-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px 16px;
    border-radius: 8px;
    cursor: pointer;
    transition: all .15s;
    position: relative;
    border-left: 3px solid transparent;
    margin-bottom: 2px;
  }
  .block-item:hover {
    background: var(--hover);
  }
  .block-item.active {
    background: var(--purple-bg);
    border-left-color: var(--purple);
  }
  .block-item.hovered:not(.active) {
    background: var(--hover);
  }

  .block-drag-handle {
    color: var(--dim);
    cursor: grab;
    display: flex;
    align-items: center;
    flex-shrink: 0;
    transition: color .15s;
  }
  .block-drag-handle:hover {
    color: var(--sec);
  }
  .block-drag-handle:active {
    cursor: grabbing;
  }

  .block-icon {
    color: var(--sec);
    display: flex;
    align-items: center;
    flex-shrink: 0;
  }
  .block-item.active .block-icon {
    color: var(--purple);
  }

  .block-name {
    font-size: 14px;
    font-weight: 500;
    color: var(--text);
    flex: 1;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .block-delete {
    opacity: 0;
    width: 28px;
    height: 28px;
    border: none;
    background: transparent;
    color: var(--dim);
    border-radius: 6px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all .15s;
    flex-shrink: 0;
  }
  .block-item:hover .block-delete {
    opacity: 1;
  }
  .block-delete:hover {
    background: rgba(220, 38, 38, .1);
    color: var(--red, #DC2626);
  }

  /* SortableJS classes */
  :global(.block-ghost) {
    opacity: .4;
    background: var(--purple-bg) !important;
  }
  :global(.block-chosen) {
    box-shadow: 0 4px 12px rgba(0,0,0,.15);
  }
  :global(.block-drag) {
    opacity: .9;
  }

  /* ===== ADD BLOCK ===== */
  .add-block-footer {
    padding: 12px 16px;
    border-top: 1px solid var(--border);
    flex-shrink: 0;
  }

  .add-block-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    width: 100%;
    padding: 12px;
    border: 1px dashed var(--purple);
    background: transparent;
    color: var(--purple);
    border-radius: 8px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: all .15s;
    font-family: var(--font);
  }
  .add-block-btn:hover {
    background: var(--purple-bg);
    border-style: solid;
  }

  /* ===== EMPTY STATE ===== */
  .empty-state {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 48px 24px;
    text-align: center;
    flex: 1;
  }

  .empty-icon {
    width: 64px;
    height: 64px;
    border-radius: 16px;
    background: var(--purple-bg);
    color: var(--purple);
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 16px;
  }

  .empty-title {
    font-size: 16px;
    font-weight: 600;
    color: var(--text);
    margin: 0 0 6px;
  }

  .empty-desc {
    font-size: 13px;
    color: var(--dim);
    margin: 0 0 20px;
    line-height: 1.5;
  }

  /* ===== LOADING ===== */
  .loading-state {
    padding: 16px;
    display: flex;
    flex-direction: column;
    gap: 8px;
  }

  .skeleton-block {
    height: 48px;
    border-radius: 8px;
    background: var(--hover);
    animation: pulse 1.5s ease-in-out infinite;
  }

  .skeleton-card {
    height: 72px;
    border-radius: 10px;
    background: var(--hover);
    animation: pulse 1.5s ease-in-out infinite;
  }

  @keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: .5; }
  }

  :global(.spin) {
    animation: spin 1s linear infinite;
  }
  @keyframes spin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
  }

  /* ===== BLOCK PICKER DRAWER ===== */
  .picker-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,.3);
    z-index: 210;
  }

  .picker-drawer {
    position: fixed;
    top: 0;
    left: 0;
    bottom: 0;
    width: 340px;
    background: var(--bg);
    z-index: 220;
    display: flex;
    flex-direction: column;
    box-shadow: 8px 0 24px rgba(0,0,0,.15);
    animation: slideInLeft .25s ease;
  }

  @keyframes slideInLeft {
    from { transform: translateX(-100%); }
    to { transform: translateX(0); }
  }

  .picker-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 16px 20px;
    border-bottom: 1px solid var(--border);
    flex-shrink: 0;
  }

  .picker-title {
    font-size: 16px;
    font-weight: 700;
    margin: 0;
    color: var(--text);
  }

  .picker-close {
    width: 32px;
    height: 32px;
    border: none;
    background: transparent;
    color: var(--dim);
    border-radius: 8px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all .15s;
  }
  .picker-close:hover {
    background: var(--hover);
    color: var(--text);
  }

  .picker-search {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px 20px;
    border-bottom: 1px solid var(--border);
    color: var(--dim);
    flex-shrink: 0;
  }

  .picker-search-input {
    flex: 1;
    border: none;
    background: transparent;
    color: var(--text);
    font-size: 14px;
    font-family: var(--font);
    outline: none;
  }
  .picker-search-input::placeholder {
    color: var(--dim);
  }

  .picker-body {
    flex: 1;
    overflow-y: auto;
    padding: 16px 20px;
  }

  .picker-category {
    margin-bottom: 24px;
  }

  .picker-category-title {
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .06em;
    color: var(--dim);
    margin: 0 0 10px;
  }

  .picker-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 8px;
  }

  .picker-card {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 8px;
    padding: 16px;
    border: 1px solid var(--border);
    border-radius: 10px;
    background: var(--raised);
    cursor: pointer;
    transition: all .15s;
    text-align: left;
    font-family: var(--font);
  }
  .picker-card:hover {
    border-color: var(--purple);
    background: var(--purple-bg);
  }

  .picker-card-icon {
    color: var(--purple);
    display: flex;
    align-items: center;
  }

  .picker-card-info {
    display: flex;
    flex-direction: column;
    gap: 2px;
  }

  .picker-card-name {
    font-size: 13px;
    font-weight: 600;
    color: var(--text);
  }

  .picker-card-desc {
    font-size: 11px;
    color: var(--dim);
    line-height: 1.4;
  }

  .picker-empty {
    text-align: center;
    padding: 32px 0;
    color: var(--dim);
    font-size: 14px;
  }

  /* ===== CENTER PANEL — PREVIEW ===== */
  .center-panel {
    flex: 1;
    min-width: 0;
    background: var(--hover);
    display: flex;
    flex-direction: column;
    overflow: hidden;
    position: relative;
  }

  :global(.dark) .center-panel {
    background: #0a0a0e;
  }

  .preview-container {
    flex: 1;
    overflow: hidden;
    padding: 0;
    display: flex;
  }

  .preview-frame {
    width: 100%;
    max-width: 1200px;
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 4px 24px rgba(0,0,0,.08);
    overflow: hidden;
    min-height: 600px;
    color: #111;
  }
  .preview-iframe {
    width: 100%;
    height: 100%;
    border: none;
    background: #fff;
  }

  .preview-loading {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 12px;
    color: var(--dim);
    font-size: 14px;
  }

  .preview-empty {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 8px;
    color: var(--dim);
    text-align: center;
    padding: 48px;
  }
  .preview-empty p {
    margin: 0;
    font-size: 15px;
    font-weight: 500;
  }
  .preview-empty-sub {
    font-size: 13px !important;
    font-weight: 400 !important;
    color: var(--dim);
  }

  /* ===== RIGHT PANEL — BLOCK EDITOR ===== */
  .right-panel {
    width: 0;
    min-width: 0;
    overflow: hidden;
    background: var(--bg);
    border-left: 1px solid var(--border);
    display: flex;
    flex-direction: column;
    transition: width .25s ease, min-width .25s ease;
  }
  .right-panel.open {
    width: 360px;
    min-width: 360px;
  }

  .right-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 16px 20px;
    border-bottom: 1px solid var(--border);
    flex-shrink: 0;
  }

  .right-title {
    font-size: 15px;
    font-weight: 700;
    margin: 0;
    color: var(--text);
  }

  .right-close {
    width: 32px;
    height: 32px;
    border: none;
    background: transparent;
    color: var(--dim);
    border-radius: 8px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all .15s;
  }
  .right-close:hover {
    background: var(--hover);
    color: var(--text);
  }

  .right-body {
    flex: 1;
    overflow-y: auto;
    padding: 20px;
  }

  /* ===== FIELD STYLES ===== */
  .field-group {
    margin-bottom: 20px;
  }

  .field-label {
    display: block;
    font-size: 14px;
    font-weight: 600;
    color: var(--sec);
    margin-bottom: 8px;
  }

  .field-input {
    height: 48px;
    font-size: 17px;
  }

  .field-textarea {
    height: auto;
    min-height: 120px;
    font-size: 15px;
    resize: vertical;
    padding: 14px 16px;
    line-height: 1.6;
  }

  .field-select {
    height: 48px;
    font-size: 15px;
    appearance: none;
    -webkit-appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg width='10' height='6' viewBox='0 0 10 6' fill='none' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M1 1L5 5L9 1' stroke='%236B7280' stroke-width='1.5' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 16px center;
    padding-right: 36px;
    cursor: pointer;
  }

  .link-fields {
    display: flex;
    flex-direction: column;
    gap: 8px;
  }

  .image-upload-placeholder {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    padding: 32px 16px;
    border: 2px dashed var(--border);
    border-radius: 10px;
    color: var(--dim);
    font-size: 14px;
    font-weight: 500;
    cursor: pointer;
    transition: all .15s;
  }
  .image-upload-placeholder:hover {
    border-color: var(--purple);
    color: var(--purple);
    background: var(--purple-bg);
  }

  .settings-divider {
    display: flex;
    align-items: center;
    gap: 12px;
    margin: 28px 0 20px;
    padding-top: 20px;
    border-top: 1px solid var(--border);
  }

  .settings-divider-label {
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .06em;
    color: var(--dim);
  }
</style>
