<script>
  import { onMount } from 'svelte';
  import { media as mediaApi, mediaFolders as foldersApi } from '$lib/api.js';
  import { mediaList, addToast, mediaFolderGrants } from '$lib/stores.js';
  import { humanFileSize, formatDate } from '$lib/utils.js';
  import UploadQueue from '../components/UploadQueue.svelte';
  import EmptyState from '$components/EmptyState.svelte';
  import ContextualTip from '$components/ContextualTip.svelte';
  import { tips } from '$lib/tips.js';

  let loading = $state(true);
  let dragover = $state(false);
  let selected = $state(null);

  // Bulk selection
  let selectedIds = $state(new Set());
  let bulkMode = $state(false);
  let lastClickedId = $state(null);

  // Search, filter & sort
  let search = $state('');
  let typeFilter = $state('all');
  let sortBy = $state('newest');

  // Folders
  let foldersList = $state([]);
  let activeFolderId = $state(null); // null = all, 'unfiled' = unfiled, number = folder
  let unfiledCount = $state(0);
  let totalFileCount = $state(0);
  let creatingFolder = $state(false);
  let newFolderName = $state('');
  let renamingFolderId = $state(null);
  let renameFolderName = $state('');
  let folderContextMenu = $state(null);
  let folderDragOver = $state(null);

  // Upload queue
  let uploadFiles = $state([]);

  function displayName(item) {
    // Strip the Unix timestamp prefix (e.g. "1772552781_") from the stored filename
    const name = item.filename || item.original_name;
    return name.replace(/^\d{10,}_/, '');
  }

  const docMimes = ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'text/csv', 'text/plain'];

  function getTypeGroup(mime) {
    if (mime.startsWith('image/')) return 'image';
    if (mime.startsWith('video/')) return 'video';
    if (docMimes.includes(mime)) return 'document';
    return 'other';
  }

  let items = $derived.by(() => {
    let list = $mediaList || [];

    // Text search
    if (search) {
      const q = search.toLowerCase();
      list = list.filter(i => (i.filename || i.original_name).toLowerCase().includes(q) || i.original_name.toLowerCase().includes(q));
    }

    // Type filter
    if (typeFilter !== 'all') {
      list = list.filter(i => getTypeGroup(i.mime_type) === typeFilter);
    }

    // Sort
    switch (sortBy) {
      case 'oldest':
        list = [...list].sort((a, b) => a.uploaded_at.localeCompare(b.uploaded_at));
        break;
      case 'newest':
        list = [...list].sort((a, b) => b.uploaded_at.localeCompare(a.uploaded_at));
        break;
      case 'name':
        list = [...list].sort((a, b) => a.original_name.localeCompare(b.original_name));
        break;
      case 'name-desc':
        list = [...list].sort((a, b) => b.original_name.localeCompare(a.original_name));
        break;
      case 'largest':
        list = [...list].sort((a, b) => b.file_size - a.file_size);
        break;
      case 'smallest':
        list = [...list].sort((a, b) => a.file_size - b.file_size);
        break;
    }

    return list;
  });

  let totalCount = $derived(($mediaList || []).length);
  let isFiltered = $derived(search !== '' || typeFilter !== 'all');

  function clearFilters() {
    search = '';
    typeFilter = 'all';
    sortBy = 'newest';
  }

  // Build folder tree from flat list
  let folderTree = $derived.by(() => {
    const map = {};
    for (const f of foldersList) map[f.id] = { ...f, children: [] };
    const roots = [];
    for (const f of foldersList) {
      if (f.parent_id && map[f.parent_id]) {
        map[f.parent_id].children.push(map[f.id]);
      } else {
        roots.push(map[f.id]);
      }
    }
    return roots;
  });

  onMount(() => {
    loadFolders();
    document.addEventListener('click', closeFolderContextMenu);
    return () => document.removeEventListener('click', closeFolderContextMenu);
  });

  // Reload media when folder filter changes
  $effect(() => {
    // Track activeFolderId
    const _ = activeFolderId;
    loadMedia();
  });

  async function loadMedia() {
    loading = true;
    try {
      const data = await mediaApi.list(activeFolderId);
      mediaList.set(data.media || []);
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      loading = false;
    }
  }

  async function loadFolders() {
    try {
      const data = await foldersApi.list();
      foldersList = data.folders || [];
      unfiledCount = data.unfiled_count || 0;
      totalFileCount = data.total_count || 0;
    } catch (err) {
      // Folder support may not be available yet
    }
  }

  // ── Upload queue integration ──
  function handleFileInput(e) {
    const files = e.target.files;
    if (files?.length) {
      uploadFiles = Array.from(files);
    }
    e.target.value = '';
  }

  function handleDrop(e) {
    e.preventDefault();
    dragover = false;
    const files = e.dataTransfer?.files;
    if (files?.length) {
      uploadFiles = Array.from(files);
    }
  }

  function handleDragOver(e) {
    e.preventDefault();
    dragover = true;
  }

  function handleDragLeave() {
    dragover = false;
  }

  function onUploadFileComplete(mediaItem) {
    mediaList.update((m) => [mediaItem, ...m]);
  }

  function onUploadComplete() {
    loadFolders(); // Refresh counts
  }

  // ── Item actions ──
  async function deleteItem(item) {
    if (!confirm(`Delete "${item.original_name}"?`)) return;
    try {
      await mediaApi.delete(item.id);
      mediaList.update((m) => m.filter((i) => i.id !== item.id));
      if (selected?.id === item.id) selected = null;
      addToast('File deleted', 'success');
      loadFolders();
    } catch (err) {
      addToast(err.message, 'error');
    }
  }

  function copyPath(item) {
    navigator.clipboard.writeText(item.path);
    addToast('Path copied to clipboard', 'success');
  }

  // ── Alt text editing ──
  let altText = $state('');
  let savingAlt = $state(false);

  $effect(() => {
    if (selected) {
      altText = selected.alt_text || '';
    }
  });

  async function saveAltText() {
    if (!selected) return;
    savingAlt = true;
    try {
      const data = await mediaApi.update(selected.id, { alt_text: altText });
      if (data.media) {
        mediaList.update(m => m.map(i => i.id === data.media.id ? data.media : i));
        selected = data.media;
      }
      addToast('Alt text saved', 'success');
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      savingAlt = false;
    }
  }

  // ── Focal point ──
  let focalX = $state(50);
  let focalY = $state(50);

  $effect(() => {
    if (selected) {
      focalX = selected.focal_x ?? 50;
      focalY = selected.focal_y ?? 50;
    }
  });

  async function handleFocalClick(e) {
    if (!selected) return;
    const rect = e.currentTarget.getBoundingClientRect();
    const x = Math.round(((e.clientX - rect.left) / rect.width) * 100);
    const y = Math.round(((e.clientY - rect.top) / rect.height) * 100);
    focalX = Math.max(0, Math.min(100, x));
    focalY = Math.max(0, Math.min(100, y));
    try {
      const data = await mediaApi.update(selected.id, { focal_x: focalX, focal_y: focalY });
      if (data.media) {
        mediaList.update(m => m.map(i => i.id === data.media.id ? data.media : i));
        selected = data.media;
      }
    } catch (err) {
      addToast(err.message, 'error');
    }
  }

  async function resetFocal() {
    focalX = 50;
    focalY = 50;
    if (!selected) return;
    try {
      const data = await mediaApi.update(selected.id, { focal_x: 50, focal_y: 50 });
      if (data.media) {
        mediaList.update(m => m.map(i => i.id === data.media.id ? data.media : i));
        selected = data.media;
      }
    } catch (err) {
      addToast(err.message, 'error');
    }
  }

  // ── Resize ──
  let resizeW = $state(0);
  let resizeH = $state(0);
  let resizeLock = $state(true);
  let resizing = $state(false);

  $effect(() => {
    if (selected && selected.width) {
      resizeW = selected.width;
      resizeH = selected.height;
    }
  });

  function onResizeW(e) {
    const w = parseInt(e.target.value) || 0;
    resizeW = w;
    if (resizeLock && selected?.width && w > 0) {
      resizeH = Math.round(selected.height * (w / selected.width));
    }
  }

  function onResizeH(e) {
    const h = parseInt(e.target.value) || 0;
    resizeH = h;
    if (resizeLock && selected?.height && h > 0) {
      resizeW = Math.round(selected.width * (h / selected.height));
    }
  }

  async function applyResize() {
    if (!selected || resizeW < 1 || resizeH < 1) return;
    if (resizeW === selected.width && resizeH === selected.height) return;
    resizing = true;
    try {
      const data = await mediaApi.transform({ id: selected.id, action: 'resize', width: resizeW, height: resizeH });
      if (data.media) {
        mediaList.update(m => m.map(i => i.id === data.media.id ? data.media : i));
        selected = data.media;
      }
      addToast('Image resized', 'success');
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      resizing = false;
    }
  }

  // ── Crop ──
  let cropX = $state(0);
  let cropY = $state(0);
  let cropW = $state(0);
  let cropH = $state(0);
  let showCrop = $state(false);
  let cropping = $state(false);

  function initCrop() {
    if (!selected) return;
    cropX = 0;
    cropY = 0;
    cropW = selected.width;
    cropH = selected.height;
    showCrop = true;
  }

  async function applyCrop() {
    if (!selected || cropW < 1 || cropH < 1) return;
    cropping = true;
    try {
      const data = await mediaApi.transform({ id: selected.id, action: 'crop', x: cropX, y: cropY, width: cropW, height: cropH });
      if (data.media) {
        mediaList.update(m => m.map(i => i.id === data.media.id ? data.media : i));
        selected = data.media;
      }
      showCrop = false;
      addToast('Image cropped', 'success');
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      cropping = false;
    }
  }

  let isRasterImage = $derived(selected && selected.mime_type?.startsWith('image/') && selected.mime_type !== 'image/svg+xml' && selected.width > 0);

  // ── Multi-folder assignment ──
  let selectedFolderIds = $state([]);
  let loadingFolderIds = $state(false);
  let showAddFolderDropdown = $state(false);

  $effect(() => {
    if (selected) {
      loadSelectedFolders(selected.id);
    } else {
      selectedFolderIds = [];
    }
  });

  async function loadSelectedFolders(mediaId) {
    loadingFolderIds = true;
    try {
      const data = await mediaApi.getFolders(mediaId);
      if (selected?.id === mediaId) {
        selectedFolderIds = data.folder_ids || [];
      }
    } catch {
      if (selected?.id === mediaId) selectedFolderIds = [];
    } finally {
      if (selected?.id === mediaId) loadingFolderIds = false;
    }
  }

  async function removeFromFolder(folderId) {
    if (!selected) return;
    const newIds = selectedFolderIds.filter(id => id !== folderId);
    try {
      await mediaApi.assignFolders(selected.id, newIds);
      selectedFolderIds = newIds;
      loadFolders();
      if (activeFolderId === folderId) loadMedia();
    } catch (err) {
      addToast(err.message, 'error');
    }
  }

  async function addToFolder(folderId) {
    if (!selected) return;
    const newIds = [...selectedFolderIds, folderId];
    try {
      await mediaApi.assignFolders(selected.id, newIds);
      selectedFolderIds = newIds;
      showAddFolderDropdown = false;
      loadFolders();
    } catch (err) {
      addToast(err.message, 'error');
    }
  }

  let availableFoldersForAdd = $derived(
    foldersList.filter(f => !selectedFolderIds.includes(f.id))
  );

  // ── Folder management ──
  async function createFolder() {
    if (!newFolderName.trim()) return;
    try {
      // Check for comma-separated names → bulk create
      if (newFolderName.includes(',')) {
        const names = newFolderName.split(',').map(n => n.trim()).filter(Boolean);
        if (names.length > 0) {
          const data = await foldersApi.bulkCreate(names, null);
          if (data.folders) foldersList = [...foldersList, ...data.folders];
          addToast(`${data.folders?.length || 0} folders created`, 'success');
        }
      } else {
        const data = await foldersApi.create({ name: newFolderName.trim(), parent_id: null });
        if (data.folder) foldersList = [...foldersList, data.folder];
      }
      newFolderName = '';
      creatingFolder = false;
    } catch (err) {
      addToast(err.message, 'error');
    }
  }

  async function createSubfolder(parentId) {
    const name = prompt('Subfolder name:');
    if (!name?.trim()) return;
    try {
      const data = await foldersApi.create({ name: name.trim(), parent_id: parentId });
      if (data.folder) foldersList = [...foldersList, data.folder];
      folderContextMenu = null;
    } catch (err) {
      addToast(err.message, 'error');
    }
  }

  function startRenameFolder(folder) {
    renamingFolderId = folder.id;
    renameFolderName = folder.name;
    folderContextMenu = null;
  }

  async function finishRenameFolder() {
    if (!renameFolderName.trim() || !renamingFolderId) return;
    const id = renamingFolderId;
    renamingFolderId = null; // Clear immediately to prevent Enter+blur double-fire
    try {
      const res = await foldersApi.update(id, { name: renameFolderName.trim() });
      const updated = res.folder || { name: renameFolderName.trim() };
      foldersList = foldersList.map(f => f.id === id ? { ...f, name: updated.name, slug: updated.slug || f.slug } : f);
    } catch (err) {
      addToast(err.message, 'error');
    }
  }

  async function deleteFolder(folder) {
    if (!confirm(`Delete folder "${folder.name}"? Files will become unfiled.`)) return;
    try {
      await foldersApi.delete(folder.id);
      foldersList = foldersList.filter(f => f.id !== folder.id);
      if (activeFolderId === folder.id) activeFolderId = null;
      folderContextMenu = null;
      loadFolders();
      loadMedia();
    } catch (err) {
      addToast(err.message, 'error');
    }
  }

  function showFolderContext(e, folder) {
    e.preventDefault();
    folderContextMenu = { folder, x: e.clientX, y: e.clientY };
  }

  function closeFolderContextMenu() {
    folderContextMenu = null;
    fileContextMenu = null;
    showBulkMoveMenu = false;
  }

  // ── Drag to folder ──
  let dragMediaId = $state(null);

  function onMediaDragStart(e, item) {
    dragMediaId = item.id;
    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('text/plain', String(item.id));
  }

  function onFolderDragOver(e, folderId) {
    e.preventDefault();
    folderDragOver = folderId;
  }

  function onFolderDragLeave() {
    folderDragOver = null;
  }

  async function onFolderDrop(e, folderId) {
    e.preventDefault();
    folderDragOver = null;
    if (folderId === null) return; // No-op for "All Files"
    const id = parseInt(e.dataTransfer.getData('text/plain'));
    if (!id) return;
    try {
      await mediaApi.moveToFolder([id], folderId, 'add');
      loadFolders();
      if (activeFolderId !== null) loadMedia();
      // Refresh folder assignments if this item is selected
      if (selected?.id === id) loadSelectedFolders(id);
      addToast('Added to folder', 'success');
    } catch (err) {
      addToast(err.message, 'error');
    }
  }

  // ── Bulk selection ──
  function enterBulkMode() {
    bulkMode = true;
    selectedIds = new Set();
    lastClickedId = null;
    selected = null;
  }

  function exitBulkMode() {
    bulkMode = false;
    selectedIds = new Set();
    lastClickedId = null;
  }

  function toggleSelect(item, e) {
    if (e?.shiftKey && lastClickedId !== null) {
      // Shift-click range select
      const ids = items.map(i => i.id);
      const from = ids.indexOf(lastClickedId);
      const to = ids.indexOf(item.id);
      if (from !== -1 && to !== -1) {
        const start = Math.min(from, to);
        const end = Math.max(from, to);
        const next = new Set(selectedIds);
        for (let i = start; i <= end; i++) {
          next.add(ids[i]);
        }
        selectedIds = next;
      }
    } else {
      const next = new Set(selectedIds);
      if (next.has(item.id)) {
        next.delete(item.id);
      } else {
        next.add(item.id);
      }
      selectedIds = next;
    }
    lastClickedId = item.id;
    // Auto-exit if nothing selected
    if (selectedIds.size === 0) {
      bulkMode = false;
      lastClickedId = null;
    }
  }

  function selectAll() {
    selectedIds = new Set(items.map(i => i.id));
  }

  function deselectAll() {
    selectedIds = new Set();
  }

  let bulkDeleting = $state(false);

  async function bulkDelete() {
    const count = selectedIds.size;
    if (!confirm(`Delete ${count} file${count !== 1 ? 's' : ''}? This cannot be undone.`)) return;
    bulkDeleting = true;
    try {
      await mediaApi.bulkDelete([...selectedIds]);
      mediaList.update(m => m.filter(i => !selectedIds.has(i.id)));
      addToast(`${count} file${count !== 1 ? 's' : ''} deleted`, 'success');
      exitBulkMode();
      loadFolders();
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      bulkDeleting = false;
    }
  }

  let bulkMoving = $state(false);

  async function bulkMove(folderId) {
    const count = selectedIds.size;
    bulkMoving = true;
    try {
      await mediaApi.moveToFolder([...selectedIds], folderId, folderId === null ? 'set' : 'add');
      addToast(`${count} file${count !== 1 ? 's' : ''} ${folderId === null ? 'unfiled' : 'added to folder'}`, 'success');
      exitBulkMode();
      loadFolders();
      if (activeFolderId !== null) loadMedia();
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      bulkMoving = false;
    }
  }

  let showBulkMoveMenu = $state(false);

  // Restricted editors (with folder grants) cannot create/edit/delete folders
  let canManageFolders = $derived($mediaFolderGrants === null);

  // ── File context menu ──
  let fileContextMenu = $state(null); // { item, x, y, folderIds, loading }

  async function showFileContext(e, item) {
    e.preventDefault();
    fileContextMenu = { item, x: e.clientX, y: e.clientY, folderIds: [], loading: true };
    try {
      const data = await mediaApi.getFolders(item.id);
      fileContextMenu = { ...fileContextMenu, folderIds: data.folder_ids || [], loading: false };
    } catch {
      fileContextMenu = { ...fileContextMenu, loading: false };
    }
  }

  function closeFileContextMenu() {
    fileContextMenu = null;
  }

  async function fileContextAddToFolder(folderId) {
    if (!fileContextMenu) return;
    const item = fileContextMenu.item;
    try {
      await mediaApi.moveToFolder([item.id], folderId, 'add');
      loadFolders();
      if (selected?.id === item.id) loadSelectedFolders(item.id);
      addToast('Added to folder', 'success');
    } catch (err) {
      addToast(err.message, 'error');
    }
    fileContextMenu = null;
  }

  // ── Resizable detail sidebar ──
  let sidebarWidth = $state(parseInt(localStorage.getItem('outpost_media_sidebar_width')) || 280);
  let resizingSidebar = $state(false);

  function onSidebarResizeStart(e) {
    e.preventDefault();
    resizingSidebar = true;
    const startX = e.clientX;
    const startWidth = sidebarWidth;

    function onMove(ev) {
      const delta = startX - ev.clientX;
      sidebarWidth = Math.max(220, Math.min(500, startWidth + delta));
    }

    function onUp() {
      resizingSidebar = false;
      localStorage.setItem('outpost_media_sidebar_width', String(sidebarWidth));
      window.removeEventListener('mousemove', onMove);
      window.removeEventListener('mouseup', onUp);
    }

    window.addEventListener('mousemove', onMove);
    window.addEventListener('mouseup', onUp);
  }
</script>

<div class="media-page">
  <!-- Page Header -->
  <div class="page-header">
    <div class="page-header-icon warning">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
    </div>
    <div class="page-header-content">
      <h1 class="page-title">Media Library</h1>
      <p class="page-subtitle">Upload and manage your media files</p>
    </div>
    <div class="page-header-actions">
      {#if !bulkMode}
        <button class="btn btn-secondary" onclick={enterBulkMode}>Select</button>
      {/if}
      <label class="btn btn-primary" style="cursor: pointer;">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
        Upload
        <input type="file" multiple onchange={handleFileInput} hidden />
      </label>
    </div>
  </div>

  <!-- Drop zone -->
  <div
    class="drop-zone"
    class:dragover
    ondrop={handleDrop}
    ondragover={handleDragOver}
    ondragleave={handleDragLeave}
    style="margin-bottom: var(--space-xl);"
  >
    <div class="drop-zone-text">
      <strong>Drop files here</strong> or click Upload above
    </div>
  </div>

  {#if loading && totalCount === 0}
    <div class="loading-overlay">
      <div class="spinner"></div>
    </div>
  {:else}
    <!-- Toolbar -->
    <div class="media-toolbar">
      <div class="media-search">
        <svg class="media-search-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <input
          type="text"
          class="media-search-input"
          placeholder="Search files..."
          bind:value={search}
        />
        {#if search}
          <button class="media-search-clear" onclick={() => search = ''}>
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
          </button>
        {/if}
      </div>

      <div class="media-type-chips">
        {#each [['all', 'All'], ['image', 'Images'], ['video', 'Videos'], ['document', 'Docs']] as [value, label]}
          <button
            class="media-chip"
            class:active={typeFilter === value}
            onclick={() => typeFilter = value}
          >{label}</button>
        {/each}
      </div>

      <select class="media-sort" bind:value={sortBy}>
        <option value="newest">Newest</option>
        <option value="oldest">Oldest</option>
        <option value="name">Name A-Z</option>
        <option value="name-desc">Name Z-A</option>
        <option value="largest">Largest</option>
        <option value="smallest">Smallest</option>
      </select>

      <span class="media-count">
        {#if isFiltered}{items.length} of {/if}{totalCount} file{totalCount !== 1 ? 's' : ''}
      </span>
    </div>

    <ContextualTip tipKey="media" message={tips.media} />

    <!-- Bulk action bar -->
    {#if bulkMode && selectedIds.size > 0}
      <div class="bulk-action-bar">
        <label class="bulk-checkbox-label">
          <input
            type="checkbox"
            checked={selectedIds.size === items.length && items.length > 0}
            onchange={() => selectedIds.size === items.length ? deselectAll() : selectAll()}
          />
          <span>{selectedIds.size} selected</span>
        </label>
        <div class="bulk-actions">
          <div class="bulk-move-wrapper">
            <button class="btn btn-secondary btn-sm" onclick={() => showBulkMoveMenu = !showBulkMoveMenu} disabled={bulkMoving}>
              {bulkMoving ? 'Adding...' : 'Add to folder\u2026'}
            </button>
            {#if showBulkMoveMenu}
              <div class="bulk-move-dropdown">
                <button class="bulk-move-option" onclick={() => { bulkMove(null); showBulkMoveMenu = false; }}>
                  Unfiled
                </button>
                {#each foldersList as folder (folder.id)}
                  <button class="bulk-move-option" onclick={() => { bulkMove(folder.id); showBulkMoveMenu = false; }}>
                    {folder.name}
                  </button>
                {/each}
              </div>
            {/if}
          </div>
          <button class="btn btn-danger btn-sm" onclick={bulkDelete} disabled={bulkDeleting}>
            {bulkDeleting ? 'Deleting...' : 'Delete'}
          </button>
          <button class="btn btn-secondary btn-sm" onclick={exitBulkMode}>Cancel</button>
        </div>
      </div>
    {:else if bulkMode}
      <div class="bulk-action-bar">
        <span class="bulk-hint">Click items to select them. Shift-click for range.</span>
        <button class="btn btn-secondary btn-sm" onclick={exitBulkMode}>Cancel</button>
      </div>
    {/if}

    {#if totalCount === 0 && !loading}
      <EmptyState
        icon='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>'
        title="No media uploaded"
        description="Drag and drop files or use the Upload button to get started."
      />
    {:else if items.length === 0 && isFiltered}
      <EmptyState searchActive={true} />
    {:else}
    <div class="media-layout-3col">
      <!-- Folder sidebar -->
      <div class="folder-sidebar">
        <button
          class="folder-item"
          class:active={activeFolderId === null}
          onclick={() => activeFolderId = null}
          ondragover={(e) => onFolderDragOver(e, null)}
          ondragleave={onFolderDragLeave}
          ondrop={(e) => onFolderDrop(e, null)}
          class:drag-over={folderDragOver === null && dragMediaId}
        >
          <span class="folder-name">All Files</span>
          <span class="folder-count">{totalFileCount}</span>
        </button>
        <button
          class="folder-item"
          class:active={activeFolderId === 'unfiled'}
          onclick={() => activeFolderId = 'unfiled'}
        >
          <span class="folder-name">Unfiled</span>
          <span class="folder-count">{unfiledCount}</span>
        </button>

        <div class="folder-divider"></div>

        {#each folderTree as folder (folder.id)}
          {@render folderNode(folder, 0)}
        {/each}

        {#if canManageFolders}
          {#if creatingFolder}
            <div class="folder-create-wrapper">
              <div class="folder-item folder-create-input">
                <input
                  type="text"
                  bind:value={newFolderName}
                  placeholder="Folder name..."
                  class="folder-inline-input"
                  onkeydown={(e) => {
                    if (e.key === 'Enter') createFolder();
                    if (e.key === 'Escape') creatingFolder = false;
                  }}
                  autofocus
                />
              </div>
              <span class="folder-create-hint">Separate with commas to create multiple</span>
            </div>
          {/if}

          <button class="folder-add-btn" onclick={() => { creatingFolder = true; newFolderName = ''; }}>
            + New Folder
          </button>
        {/if}
      </div>

      <!-- Media grid -->
      <div class="media-layout-main">
        {#if loading}
          <div class="loading-overlay"><div class="spinner"></div></div>
        {:else if items.length === 0}
          <div class="media-empty-filter">
            <p>No files in this folder</p>
          </div>
        {:else}
        <div class="media-grid">
          {#each items as item (item.id)}
            <div
              class="media-item"
              class:selected={!bulkMode && selected?.id === item.id}
              class:bulk-selected={bulkMode && selectedIds.has(item.id)}
              onclick={(e) => {
                if (bulkMode) {
                  toggleSelect(item, e);
                } else {
                  selected = item;
                }
              }}
              role="button"
              tabindex="0"
              onkeydown={(e) => {
                if (e.key === 'Enter') {
                  if (bulkMode) toggleSelect(item, e);
                  else selected = item;
                }
              }}
              oncontextmenu={(e) => !bulkMode && showFileContext(e, item)}
              draggable={!bulkMode}
              ondragstart={(e) => !bulkMode && onMediaDragStart(e, item)}
              ondragend={() => dragMediaId = null}
            >
              {#if bulkMode}
                <div class="bulk-checkbox-overlay">
                  <input
                    type="checkbox"
                    checked={selectedIds.has(item.id)}
                    onclick={(e) => e.stopPropagation()}
                    onchange={() => toggleSelect(item)}
                  />
                </div>
              {/if}
              {#if item.mime_type.startsWith('image/')}
                <img
                  src={item.thumb_path || item.path}
                  alt={item.alt_text || item.original_name}
                  loading="lazy"
                />
              {:else}
                <div style="display: flex; align-items: center; justify-content: center; height: 100%; color: var(--text-tertiary); font-size: var(--font-size-xs);">
                  {item.mime_type.split('/')[1]?.toUpperCase()}
                </div>
              {/if}
              <div class="media-item-name">{displayName(item)}</div>
            </div>
          {/each}
        </div>
        {/if}
      </div>

      <!-- Detail sidebar -->
      {#if selected && !bulkMode}
        <div class="media-sidebar" style="width: {sidebarWidth}px;">
          <div class="sidebar-resize-handle" onmousedown={onSidebarResizeStart}></div>
          <h3 class="media-sidebar-title">Details</h3>
          {#if selected.mime_type.startsWith('image/')}
            <!-- Focal point picker -->
            {#if isRasterImage}
              <div class="focal-container" onclick={handleFocalClick} style="cursor: crosshair;">
                <img
                  src={selected.path}
                  alt={selected.alt_text || selected.original_name}
                  class="media-sidebar-preview"
                  style="margin-bottom: 0;"
                />
                <div class="focal-marker" style="left: {focalX}%; top: {focalY}%"></div>
              </div>
              <div class="focal-info">
                <span class="focal-label">Focal point: {focalX}%, {focalY}%</span>
                {#if focalX !== 50 || focalY !== 50}
                  <button class="focal-reset" onclick={resetFocal}>Reset</button>
                {/if}
              </div>
            {:else}
              <img
                src={selected.path}
                alt={selected.alt_text || selected.original_name}
                class="media-sidebar-preview"
              />
            {/if}
          {/if}
          <div class="media-sidebar-meta">
            <div><strong>Name:</strong> {displayName(selected)}</div>
            <div><strong>Size:</strong> {humanFileSize(selected.file_size)}</div>
            {#if selected.width}
              <div><strong>Dimensions:</strong> {selected.width} &times; {selected.height}</div>
            {/if}
            <div><strong>Type:</strong> {selected.mime_type}</div>
            <div><strong>Uploaded:</strong> {formatDate(selected.uploaded_at)}</div>
          </div>

          <!-- Folders -->
          <div class="media-sidebar-section">
            <label class="media-sidebar-label">Folders</label>
            {#if loadingFolderIds}
              <span class="folder-loading-text">Loading...</span>
            {:else if selectedFolderIds.length === 0}
              <span class="folder-none-text">No folders assigned</span>
            {:else}
              <div class="folder-chips">
                {#each selectedFolderIds as fid}
                  {@const folder = foldersList.find(f => f.id === fid)}
                  {#if folder}
                    <span class="folder-chip">
                      {folder.name}
                      <button class="folder-chip-remove" onclick={() => removeFromFolder(fid)} title="Remove from folder">
                        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                      </button>
                    </span>
                  {/if}
                {/each}
              </div>
            {/if}
            {#if availableFoldersForAdd.length > 0}
              <div class="folder-add-wrapper" style="margin-top: var(--space-xs);">
                <button class="folder-add-link" onclick={() => showAddFolderDropdown = !showAddFolderDropdown}>
                  + Add to folder
                </button>
                {#if showAddFolderDropdown}
                  <div class="folder-add-dropdown">
                    {#each availableFoldersForAdd as f (f.id)}
                      <button class="folder-add-option" onclick={() => addToFolder(f.id)}>{f.name}</button>
                    {/each}
                  </div>
                {/if}
              </div>
            {/if}
          </div>

          <!-- Alt text -->
          <div class="media-sidebar-section">
            <label class="media-sidebar-label">Alt text</label>
            <div class="media-alt-row">
              <input
                type="text"
                class="media-alt-input"
                bind:value={altText}
                placeholder="Describe the image..."
                onkeydown={(e) => e.key === 'Enter' && saveAltText()}
              />
              <button class="btn btn-secondary btn-sm" onclick={saveAltText} disabled={savingAlt}>
                {savingAlt ? '...' : 'Save'}
              </button>
            </div>
          </div>

          <!-- Resize (images only) -->
          {#if isRasterImage}
            <div class="media-sidebar-section">
              <label class="media-sidebar-label">Resize</label>
              <div class="media-resize-row">
                <input type="number" class="media-dim-input" value={resizeW} oninput={onResizeW} min="1" max="4800" />
                <button
                  class="media-lock-btn"
                  class:active={resizeLock}
                  onclick={() => resizeLock = !resizeLock}
                  title={resizeLock ? 'Unlock aspect ratio' : 'Lock aspect ratio'}
                >
                  {#if resizeLock}
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                  {:else}
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 9.9-1"/></svg>
                  {/if}
                </button>
                <input type="number" class="media-dim-input" value={resizeH} oninput={onResizeH} min="1" max="4800" />
              </div>
              <button
                class="btn btn-secondary btn-sm"
                style="width: 100%; margin-top: var(--space-xs);"
                onclick={applyResize}
                disabled={resizing || (resizeW === selected.width && resizeH === selected.height)}
              >
                {resizing ? 'Resizing...' : 'Apply Resize'}
              </button>
            </div>

            <!-- Crop -->
            <div class="media-sidebar-section">
              <label class="media-sidebar-label">Crop</label>
              {#if showCrop}
                <div class="media-crop-inputs">
                  <div class="media-crop-grid">
                    <label class="media-crop-field">
                      <span>X</span>
                      <input type="number" bind:value={cropX} min="0" max={selected.width - 1} />
                    </label>
                    <label class="media-crop-field">
                      <span>Y</span>
                      <input type="number" bind:value={cropY} min="0" max={selected.height - 1} />
                    </label>
                    <label class="media-crop-field">
                      <span>W</span>
                      <input type="number" bind:value={cropW} min="1" max={selected.width - cropX} />
                    </label>
                    <label class="media-crop-field">
                      <span>H</span>
                      <input type="number" bind:value={cropH} min="1" max={selected.height - cropY} />
                    </label>
                  </div>
                  <div style="display: flex; gap: var(--space-xs); margin-top: var(--space-xs);">
                    <button class="btn btn-primary btn-sm" style="flex: 1;" onclick={applyCrop} disabled={cropping}>
                      {cropping ? 'Cropping...' : 'Apply Crop'}
                    </button>
                    <button class="btn btn-secondary btn-sm" onclick={() => showCrop = false}>Cancel</button>
                  </div>
                </div>
              {:else}
                <button class="btn btn-secondary btn-sm" style="width: 100%;" onclick={initCrop}>
                  Crop Image
                </button>
              {/if}
            </div>
          {/if}

          <div style="display: flex; gap: var(--space-xs); margin-top: var(--space-md);">
            <button class="btn btn-secondary btn-sm" onclick={() => copyPath(selected)} style="flex: 1;">Copy Path</button>
            <button class="btn btn-danger btn-sm" onclick={() => deleteItem(selected)}>Delete</button>
          </div>
        </div>
      {/if}
    </div>
    {/if}
  {/if}
</div>

<!-- Folder context menu -->
{#if folderContextMenu}
  <div class="context-menu" style="left: {folderContextMenu.x}px; top: {folderContextMenu.y}px;">
    <button class="context-menu-item" onclick={() => startRenameFolder(folderContextMenu.folder)}>Rename</button>
    <button class="context-menu-item" onclick={() => createSubfolder(folderContextMenu.folder.id)}>New subfolder</button>
    <button class="context-menu-item" onclick={() => { navigator.clipboard.writeText(`{%- for img in media_folder.${folderContextMenu.folder.slug || folderContextMenu.folder.name.toLowerCase().replace(/[^a-z0-9-]/g, '-').replace(/-+/g, '-').replace(/^-|-$/g, '')} -%}{{ img.url }}{%- endfor -%}`); addToast('Template tag copied', 'success'); folderContextMenu = null; }}>Copy template tag</button>
    <button class="context-menu-item danger" onclick={() => deleteFolder(folderContextMenu.folder)}>Delete</button>
  </div>
{/if}

<!-- File context menu -->
{#if fileContextMenu}
  <div class="context-menu" style="left: {fileContextMenu.x}px; top: {fileContextMenu.y}px;">
    {#if fileContextMenu.loading}
      <div class="context-menu-item" style="color: var(--text-tertiary); cursor: default;">Loading...</div>
    {:else}
      {#if fileContextMenu.folderIds.length > 0}
        <div class="context-menu-section-label">In folders:</div>
        {#each fileContextMenu.folderIds as fid}
          {@const folder = foldersList.find(f => f.id === fid)}
          {#if folder}
            <div class="context-menu-badge">{folder.name}</div>
          {/if}
        {/each}
        <div class="context-menu-divider"></div>
      {/if}
      {@const unassignedFolders = foldersList.filter(f => !fileContextMenu.folderIds.includes(f.id))}
      {#if unassignedFolders.length > 0}
        <div class="context-menu-section-label">Add to folder:</div>
        {#each unassignedFolders as f (f.id)}
          <button class="context-menu-item" onclick={() => fileContextAddToFolder(f.id)}>{f.name}</button>
        {/each}
        <div class="context-menu-divider"></div>
      {/if}
    {/if}
    <button class="context-menu-item" onclick={() => { copyPath(fileContextMenu.item); fileContextMenu = null; }}>Copy Path</button>
    <button class="context-menu-item danger" onclick={() => { deleteItem(fileContextMenu.item); fileContextMenu = null; }}>Delete</button>
  </div>
{/if}

<!-- Upload Queue -->
<UploadQueue
  files={uploadFiles}
  folderId={typeof activeFolderId === 'number' ? activeFolderId : null}
  onfileComplete={onUploadFileComplete}
  oncomplete={onUploadComplete}
/>

{#snippet folderNode(folder, depth)}
  <button
    class="folder-item"
    class:active={activeFolderId === folder.id}
    class:drag-over={folderDragOver === folder.id}
    style="padding-left: {12 + depth * 16}px;"
    onclick={() => activeFolderId = folder.id}
    oncontextmenu={(e) => canManageFolders && showFolderContext(e, folder)}
    ondragover={(e) => onFolderDragOver(e, folder.id)}
    ondragleave={onFolderDragLeave}
    ondrop={(e) => onFolderDrop(e, folder.id)}
  >
    {#if renamingFolderId === folder.id}
      <input
        type="text"
        class="folder-inline-input"
        bind:value={renameFolderName}
        onkeydown={(e) => {
          if (e.key === 'Enter') finishRenameFolder();
          if (e.key === 'Escape') renamingFolderId = null;
        }}
        onblur={finishRenameFolder}
        onclick={(e) => e.stopPropagation()}
        autofocus
      />
    {:else}
      <svg class="folder-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M22 19a2 2 0 01-2 2H4a2 2 0 01-2-2V5a2 2 0 012-2h5l2 3h9a2 2 0 012 2z"/></svg>
      <span class="folder-name">{folder.name}</span>
      <span class="folder-count">{folder.file_count ?? 0}</span>
    {/if}
  </button>
  {#if folder.children?.length}
    {#each folder.children as child (child.id)}
      {@render folderNode(child, depth + 1)}
    {/each}
  {/if}
{/snippet}

<style>
  .media-page {
    max-width: var(--content-width-wide);
  }

  /* Toolbar */
  .media-toolbar {
    display: flex;
    align-items: center;
    gap: var(--space-md);
    margin-bottom: var(--space-lg);
  }

  /* Search */
  .media-search {
    position: relative;
    flex: 1;
    max-width: 280px;
  }
  .media-search-icon {
    position: absolute;
    left: 0;
    top: 50%;
    transform: translateY(-50%);
    color: var(--text-tertiary);
    pointer-events: none;
  }
  .media-search-input {
    width: 100%;
    padding: 6px 24px 6px 20px;
    border: 1px solid transparent;
    border-radius: var(--radius-md);
    background: transparent;
    font-size: var(--font-size-sm);
    color: var(--text-primary);
    outline: none;
    transition: border-color 0.15s;
  }
  .media-search-input:hover,
  .media-search-input:focus {
    border-color: var(--border-color);
  }
  .media-search-input::placeholder {
    color: var(--text-tertiary);
  }
  .media-search-clear {
    position: absolute;
    right: 4px;
    top: 50%;
    transform: translateY(-50%);
    background: none;
    border: none;
    padding: 2px;
    cursor: pointer;
    color: var(--text-tertiary);
    display: flex;
    align-items: center;
  }
  .media-search-clear:hover {
    color: var(--text-primary);
  }

  /* Type chips */
  .media-type-chips {
    display: flex;
    gap: var(--space-xs);
  }
  .media-chip {
    background: none;
    border: none;
    padding: 4px 8px;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--text-tertiary);
    cursor: pointer;
    border-radius: var(--radius-sm);
    transition: color 0.15s;
  }
  .media-chip:hover {
    color: var(--text-primary);
  }
  .media-chip.active {
    color: var(--text-primary);
    font-weight: 600;
  }

  /* Sort */
  .media-sort {
    padding: 4px 8px;
    border: 1px solid transparent;
    border-radius: var(--radius-md);
    background: transparent;
    font-size: var(--font-size-xs);
    color: var(--text-secondary);
    cursor: pointer;
    outline: none;
    transition: border-color 0.15s;
  }
  .media-sort:hover,
  .media-sort:focus {
    border-color: var(--border-color);
  }

  /* Count */
  .media-count {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--text-tertiary);
    white-space: nowrap;
    margin-left: auto;
  }

  /* Empty filter state */
  .media-empty-filter {
    text-align: center;
    padding: var(--space-3xl) 0;
    color: var(--text-tertiary);
  }
  .media-empty-filter p {
    font-size: var(--font-size-sm);
    margin-bottom: var(--space-sm);
  }
  .media-clear-link {
    background: none;
    border: none;
    color: var(--text-secondary);
    text-decoration: underline;
    cursor: pointer;
    font-size: var(--font-size-sm);
    padding: 0;
  }
  .media-clear-link:hover {
    color: var(--text-primary);
  }

  /* 3-column layout: folder sidebar | grid | detail sidebar */
  .media-layout-3col {
    display: flex;
    gap: var(--space-xl);
  }

  /* Folder sidebar */
  /* Folder sidebar styles: see admin.css (shared with LabelSidebar) */

  /* Detail sidebar */
  .media-sidebar {
    flex-shrink: 0;
    align-self: flex-start;
    background: var(--bg-secondary);
    border-radius: var(--radius-lg);
    padding: var(--space-lg);
    position: relative;
  }
  .sidebar-resize-handle {
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    width: 6px;
    cursor: col-resize;
    background: transparent;
    border-radius: var(--radius-lg) 0 0 var(--radius-lg);
    transition: background 0.15s;
    z-index: 2;
  }
  .sidebar-resize-handle:hover {
    background: var(--border-color);
  }
  .media-sidebar-title {
    font-size: var(--font-size-sm);
    font-weight: 600;
    margin-bottom: var(--space-md);
  }
  .media-sidebar-preview {
    width: 100%;
    border-radius: var(--radius-md);
    margin-bottom: var(--space-md);
  }
  .media-sidebar-meta {
    font-size: var(--font-size-xs);
    color: var(--text-secondary);
    display: flex;
    flex-direction: column;
    gap: var(--space-xs);
  }
  .media-sidebar-section {
    margin-top: var(--space-md);
    padding-top: var(--space-md);
    border-top: 1px solid var(--border-color);
  }
  .media-sidebar-label {
    display: block;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--text-tertiary);
    margin-bottom: var(--space-xs);
  }

  /* Focal point */
  .focal-container {
    position: relative;
    margin-bottom: var(--space-sm);
  }
  .focal-container img {
    display: block;
    width: 100%;
    border-radius: var(--radius-md);
  }
  .focal-marker {
    position: absolute;
    width: 16px;
    height: 16px;
    border-radius: 50%;
    background: white;
    border: 2px solid rgba(0,0,0,0.6);
    box-shadow: 0 1px 4px rgba(0,0,0,0.3);
    transform: translate(-50%, -50%);
    pointer-events: none;
  }
  .focal-info {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: var(--space-md);
  }
  .focal-label {
    font-size: 10px;
    color: var(--text-tertiary);
  }
  .focal-reset {
    background: none;
    border: none;
    font-size: 10px;
    color: var(--text-tertiary);
    cursor: pointer;
    text-decoration: underline;
    padding: 0;
  }
  .focal-reset:hover {
    color: var(--text-primary);
  }

  /* Folder chips in sidebar */
  .folder-chips {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
  }
  .folder-chip {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 2px 8px;
    background: var(--bg-primary);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-sm);
    font-size: 11px;
    color: var(--text-secondary);
  }
  .folder-chip-remove {
    background: none;
    border: none;
    padding: 0;
    cursor: pointer;
    color: var(--text-tertiary);
    display: flex;
    align-items: center;
    line-height: 1;
  }
  .folder-chip-remove:hover {
    color: var(--color-danger, #ef4444);
  }
  .folder-loading-text,
  .folder-none-text {
    font-size: 11px;
    color: var(--text-tertiary);
  }
  .folder-add-wrapper {
    position: relative;
  }
  .folder-add-link {
    background: none;
    border: none;
    padding: 0;
    font-size: 11px;
    color: var(--text-tertiary);
    cursor: pointer;
  }
  .folder-add-link:hover {
    color: var(--text-primary);
  }
  .folder-add-dropdown {
    position: absolute;
    top: 100%;
    left: 0;
    margin-top: 4px;
    background: var(--bg-primary);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-md);
    box-shadow: 0 4px 16px rgba(0,0,0,0.12);
    z-index: 100;
    min-width: 160px;
    padding: 4px;
    max-height: 200px;
    overflow-y: auto;
  }
  .folder-add-option {
    display: block;
    width: 100%;
    padding: 6px 12px;
    border: none;
    background: none;
    font-size: var(--font-size-xs);
    color: var(--text-primary);
    cursor: pointer;
    text-align: left;
    border-radius: var(--radius-sm);
  }
  .folder-add-option:hover {
    background: var(--bg-secondary);
  }

  /* Alt text */
  .media-alt-row {
    display: flex;
    gap: var(--space-xs);
  }
  .media-alt-input {
    flex: 1;
    padding: 5px 8px;
    border: 1px solid transparent;
    border-radius: var(--radius-md);
    background: transparent;
    font-size: var(--font-size-xs);
    color: var(--text-primary);
    outline: none;
    transition: border-color 0.15s;
  }
  .media-alt-input:hover,
  .media-alt-input:focus {
    border-color: var(--border-color);
  }

  /* Resize */
  .media-resize-row {
    display: flex;
    align-items: center;
    gap: var(--space-xs);
  }
  .media-dim-input {
    flex: 1;
    width: 0;
    padding: 5px 8px;
    border: 1px solid transparent;
    border-radius: var(--radius-md);
    background: transparent;
    font-size: var(--font-size-xs);
    color: var(--text-primary);
    outline: none;
    transition: border-color 0.15s;
    text-align: center;
  }
  .media-dim-input:hover,
  .media-dim-input:focus {
    border-color: var(--border-color);
  }
  .media-lock-btn {
    background: none;
    border: none;
    padding: 4px;
    cursor: pointer;
    color: var(--text-tertiary);
    border-radius: var(--radius-sm);
    display: flex;
    align-items: center;
    transition: color 0.15s;
  }
  .media-lock-btn:hover {
    color: var(--text-primary);
  }
  .media-lock-btn.active {
    color: var(--text-secondary);
  }

  /* Crop */
  .media-crop-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: var(--space-xs);
  }
  .media-crop-field {
    display: flex;
    align-items: center;
    gap: 4px;
    font-size: var(--font-size-xs);
    color: var(--text-tertiary);
  }
  .media-crop-field input {
    flex: 1;
    width: 0;
    padding: 4px 6px;
    border: 1px solid transparent;
    border-radius: var(--radius-sm);
    background: transparent;
    font-size: var(--font-size-xs);
    color: var(--text-primary);
    outline: none;
    text-align: center;
    transition: border-color 0.15s;
  }
  .media-crop-field input:hover,
  .media-crop-field input:focus {
    border-color: var(--border-color);
  }

  .media-layout-main {
    flex: 1;
    min-width: 0;
  }

  /* Context menu */
  :global(.context-menu) {
    position: fixed;
    background: var(--bg-primary);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-md);
    box-shadow: 0 4px 16px rgba(0,0,0,0.12);
    z-index: 1001;
    min-width: 160px;
    padding: 4px;
  }
  :global(.context-menu-item) {
    display: block;
    width: 100%;
    padding: 6px 12px;
    border: none;
    background: none;
    font-size: var(--font-size-xs);
    color: var(--text-primary);
    cursor: pointer;
    text-align: left;
    border-radius: var(--radius-sm);
  }
  :global(.context-menu-item:hover) {
    background: var(--bg-secondary);
  }
  :global(.context-menu-item.danger) {
    color: var(--color-danger, #ef4444);
  }
  :global(.context-menu-section-label) {
    padding: 4px 12px 2px;
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--text-tertiary);
  }
  :global(.context-menu-badge) {
    padding: 2px 12px;
    font-size: var(--font-size-xs);
    color: var(--text-secondary);
  }
  :global(.context-menu-divider) {
    height: 1px;
    background: var(--border-color);
    margin: 4px 8px;
  }

  /* Bulk action bar */
  .bulk-action-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 8px 12px;
    margin-bottom: var(--space-md);
    background: var(--bg-secondary);
    border-radius: var(--radius-md);
    font-size: var(--font-size-sm);
    gap: var(--space-md);
  }
  .bulk-checkbox-label {
    display: flex;
    align-items: center;
    gap: var(--space-xs);
    font-weight: 500;
    cursor: pointer;
    color: var(--text-primary);
  }
  .bulk-checkbox-label input[type="checkbox"] {
    margin: 0;
    cursor: pointer;
  }
  .bulk-actions {
    display: flex;
    align-items: center;
    gap: var(--space-xs);
  }
  .bulk-hint {
    font-size: var(--font-size-xs);
    color: var(--text-tertiary);
  }
  .bulk-move-wrapper {
    position: relative;
  }
  .bulk-move-dropdown {
    position: absolute;
    top: 100%;
    left: 0;
    margin-top: 4px;
    background: var(--bg-primary);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-md);
    box-shadow: 0 4px 16px rgba(0,0,0,0.12);
    z-index: 100;
    min-width: 180px;
    padding: 4px;
    max-height: 240px;
    overflow-y: auto;
  }
  .bulk-move-option {
    display: block;
    width: 100%;
    padding: 6px 12px;
    border: none;
    background: none;
    font-size: var(--font-size-xs);
    color: var(--text-primary);
    cursor: pointer;
    text-align: left;
    border-radius: var(--radius-sm);
  }
  .bulk-move-option:hover {
    background: var(--bg-secondary);
  }

  /* Bulk checkbox overlay on grid items */
  .bulk-checkbox-overlay {
    position: absolute;
    top: 6px;
    left: 6px;
    z-index: 2;
  }
  .bulk-checkbox-overlay input[type="checkbox"] {
    width: 16px;
    height: 16px;
    cursor: pointer;
    margin: 0;
  }

  :global(.media-item.bulk-selected) {
    border-color: var(--accent);
    box-shadow: 0 0 0 2px var(--accent);
  }

  /* ── Mobile ── */
  @media (max-width: 768px) {
    .media-layout-3col {
      flex-direction: column;
    }

    .folder-sidebar {
      width: 100%;
      display: flex;
      flex-wrap: wrap;
      gap: 2px;
    }

    .folder-divider {
      display: none;
    }

    .media-sidebar {
      width: 100%;
      order: -1;
    }

    .media-toolbar {
      flex-wrap: wrap;
      gap: var(--space-sm);
    }

    .media-search {
      flex: none;
      width: 100%;
      max-width: none;
    }

    .media-type-chips {
      flex-wrap: wrap;
    }

    .media-resize-row {
      flex-wrap: wrap;
    }

    .media-crop-grid {
      grid-template-columns: 1fr;
    }
  }
</style>
