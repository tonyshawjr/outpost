<script>
  import { auth, collections as collectionsApi, stats as statsApi } from '$lib/api.js';
  import {
    currentRoute,
    navigate,
    user,
    sidebarOpen,
    searchOpen,
    collectionsList,
    currentCollectionSlug,
    currentStatusFilter,
    currentFolderCollectionId,
    currentFolderId,
    statsData,
    canManageUsers,
    canManageSettings,
    canAccessCodeEditor,
    canManageMembers,
    canManageChannels,
    canBuildForms,
    collectionGrants,
  } from '$lib/stores.js';
  import { onMount } from 'svelte';
  import outpostLogo from '../assets/outpost.svg';

  let route = $derived($currentRoute);
  let open = $derived($sidebarOpen);
  let colls = $derived($collectionsList);
  let activeCollSlug = $derived($currentCollectionSlug);
  let activeStatusFilter = $derived($currentStatusFilter);
  let activeFolderCollId = $derived($currentFolderCollectionId);
  let activeFolderId = $derived($currentFolderId);
  let data = $derived($statsData);
  let showUsers = $derived($canManageUsers);
  let showSettings = $derived($canManageSettings);
  let showCode = $derived($canAccessCodeEditor);
  let showMembers = $derived($canManageMembers);
  let showChannels = $derived($canManageChannels);
  let showFormBuilder = $derived($canBuildForms);
  let grants = $derived($collectionGrants);
  let filteredColls = $derived(
    grants === null ? colls : colls.filter(c => grants.includes(c.id))
  );
  let expandedColls = $state({});

  function toggleCollExpand(slug) {
    expandedColls = { ...expandedColls, [slug]: !expandedColls[slug] };
  }

  function getCollStats(slug) {
    if (!data?.collections) return null;
    return data.collections.find(c => c.slug === slug);
  }

  onMount(async () => {
    try {
      const data = await collectionsApi.list();
      collectionsList.set(data.collections || []);
    } catch (e) {}
    try {
      const s = await statsApi.get();
      statsData.set(s);
    } catch (e) {}
  });

  async function handleLogout() {
    await auth.logout();
    user.set(null);
  }

  function nav(r, params = {}) {
    navigate(r, params);
    if (window.innerWidth < 768) sidebarOpen.set(false);
  }

  function isSubActive(collSlug, status) {
    return route === 'collection-items' && activeCollSlug === collSlug && activeStatusFilter === status;
  }

  function isFolderSubActive(collId, fId) {
    return (route === 'folder-labels' || route === 'folder-label-edit') && activeFolderCollId === collId && activeFolderId === fId;
  }
</script>

<aside class="sidebar" class:open={open}>
  <div class="sidebar-logo sidebar-inner">
    <img src={outpostLogo} alt="Outpost" style="height: 11px; width: auto; filter: brightness(0) invert(1);" />
  </div>

  <button class="sidebar-search-trigger sidebar-inner" onclick={() => searchOpen.set(true)}>
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="sidebar-search-icon">
      <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
    </svg>
    <span class="sidebar-search-hint">Search...</span>
    <span class="sidebar-search-kbd">⌘K</span>
  </button>

  <!-- Content -->
  <div class="sidebar-section sidebar-inner">
    <button
      class="sidebar-item"
      class:active={route === 'dashboard'}
      onclick={() => nav('dashboard')}
    >
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="12" r="10"/><polygon points="16.24 7.76 14.12 14.12 7.76 16.24 9.88 9.88 16.24 7.76"/></svg>
      Dashboard
    </button>
    {#if showCode}
      <button
        class="sidebar-item"
        class:active={route === 'analytics' || route.startsWith('analytics-')}
        onclick={() => nav('analytics')}
      >
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
        Analytics
      </button>
    {/if}
    <button
      class="sidebar-item"
      class:active={route === 'pages'}
      onclick={() => nav('pages')}
    >
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
      Pages
    </button>
    <button
      class="sidebar-item"
      class:active={route === 'media'}
      onclick={() => nav('media')}
    >
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
      Media
    </button>
    <button
      class="sidebar-item"
      class:active={route === 'globals'}
      onclick={() => nav('globals')}
    >
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 014 10 15.3 15.3 0 01-4 10 15.3 15.3 0 01-4-10 15.3 15.3 0 014-10z"/></svg>
      Globals
    </button>
    <button
      class="sidebar-item"
      class:active={route === 'navigation'}
      onclick={() => nav('navigation')}
    >
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
      Navigation
    </button>
    <button
      class="sidebar-item"
      class:active={route === 'forms' || route === 'form-submissions'}
      onclick={() => nav('form-submissions')}
    >
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
      Inbox
    </button>
  </div>

  <!-- Collections (content) -->
  {#if filteredColls.length > 0}
    <div class="sidebar-divider"></div>
    <div class="sidebar-section sidebar-inner">
      <div class="sidebar-label">Collections</div>
      {#each filteredColls as coll}
        {@const stats = getCollStats(coll.slug)}
        {@const isExpanded = expandedColls[coll.slug]}
        <div class="sidebar-coll-group">
          <button
            class="sidebar-item"
            class:active={route === 'collection-items' && activeCollSlug === coll.slug && activeStatusFilter === 'all'}
            onclick={() => toggleCollExpand(coll.slug)}
          >
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M22 19a2 2 0 01-2 2H4a2 2 0 01-2-2V5a2 2 0 012-2h5l2 3h9a2 2 0 012 2z"/></svg>
            {coll.name}
            <svg class="sidebar-coll-chevron" class:rotated={isExpanded} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
          </button>
          {#if isExpanded}
            <div class="sidebar-sub-items">
              <button class="sidebar-sub-item" class:sub-active={isSubActive(coll.slug, 'all')} onclick={() => nav('collection-items', { collectionSlug: coll.slug, statusFilter: 'all' })}>
                <span class="sidebar-sub-text">All Items</span><span class="sidebar-sub-count">{stats?.item_count ?? coll.item_count ?? 0}</span>
              </button>
              <button class="sidebar-sub-item" class:sub-active={isSubActive(coll.slug, 'draft')} onclick={() => nav('collection-items', { collectionSlug: coll.slug, statusFilter: 'draft' })}>
                <span class="sidebar-sub-text">Drafts</span><span class="sidebar-sub-count">{stats?.draft_count ?? 0}</span>
              </button>
              <button class="sidebar-sub-item" class:sub-active={isSubActive(coll.slug, 'scheduled')} onclick={() => nav('collection-items', { collectionSlug: coll.slug, statusFilter: 'scheduled' })}>
                <span class="sidebar-sub-text">Scheduled</span><span class="sidebar-sub-count">{stats?.scheduled_count ?? 0}</span>
              </button>
              <button class="sidebar-sub-item" class:sub-active={isSubActive(coll.slug, 'published')} onclick={() => nav('collection-items', { collectionSlug: coll.slug, statusFilter: 'published' })}>
                <span class="sidebar-sub-text">Published</span><span class="sidebar-sub-count">{stats?.published_count ?? 0}</span>
              </button>
              {#if stats?.taxonomies?.length}
                <div class="sidebar-sub-divider"></div>
                {#each stats.taxonomies as tax}
                  <button class="sidebar-sub-item" class:sub-active={isFolderSubActive(coll.id, tax.id)} onclick={() => nav('folder-labels', { folderCollectionId: coll.id, folderId: tax.id })}>
                    <span class="sidebar-sub-text">{tax.name}</span>
                  </button>
                {/each}
              {/if}
            </div>
          {/if}
        </div>
      {/each}
    </div>
  {/if}

  <!-- Build (structure/design tools) -->
  <div class="sidebar-divider"></div>
  <div class="sidebar-section sidebar-inner">
    <div class="sidebar-label">Build</div>
    <button
      class="sidebar-item"
      class:active={route === 'collections'}
      onclick={() => nav('collections')}
    >
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
      Collections
    </button>
    {#if showFormBuilder}
      <button
        class="sidebar-item"
        class:active={route === 'forms-list' || route === 'form-builder'}
        onclick={() => nav('forms-list')}
      >
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M9 11H3v10h6V11z"/><path d="M21 3H3v6h18V3z"/><path d="M21 11h-6v10h6V11z"/></svg>
        Form Builder
      </button>
    {/if}
    {#if showChannels}
      <button
        class="sidebar-item"
        class:active={route === 'channels' || route === 'channel-builder'}
        onclick={() => nav('channels')}
      >
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M4.9 19.1C1 15.2 1 8.8 4.9 4.9"/><path d="M7.8 16.2c-2.3-2.3-2.3-6.1 0-8.4"/><circle cx="12" cy="12" r="2"/><path d="M16.2 7.8c2.3 2.3 2.3 6.1 0 8.4"/><path d="M19.1 4.9C23 8.8 23 15.2 19.1 19.1"/></svg>
        Channels
      </button>
    {/if}
    <button
      class="sidebar-item"
      class:active={route === 'folder-manager'}
      onclick={() => nav('folder-manager')}
    >
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M20.59 13.41l-7.17 7.17a2 2 0 01-2.83 0L2 12V2h10l8.59 8.59a2 2 0 010 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>
      Folders
    </button>
    {#if showSettings}
      <button
        class="sidebar-item"
        class:active={route === 'themes'}
        onclick={() => nav('themes')}
      >
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>
        Themes
      </button>
    {/if}
    {#if showCode}
      <button
        class="sidebar-item"
        class:active={route === 'code-editor'}
        onclick={() => nav('code-editor')}
      >
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>
        Code Editor
      </button>
      <button
        class="sidebar-item"
        class:active={route === 'template-reference'}
        onclick={() => nav('template-reference')}
      >
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M4 19.5A2.5 2.5 0 016.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 014 19.5v-15A2.5 2.5 0 016.5 2z"/></svg>
        Template Ref
      </button>
    {/if}
  </div>

  <!-- Admin -->
  <div class="sidebar-divider"></div>
  <div class="sidebar-section sidebar-inner" style="margin-top: auto;">
    {#if showSettings}
      <button
        class="sidebar-item"
        class:active={route === 'settings' || route === 'user-profile'}
        onclick={() => nav('settings')}
      >
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83 0 2 2 0 010-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"/></svg>
        Settings
      </button>
    {/if}
    <button class="sidebar-item" onclick={handleLogout}>
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
      Log out
    </button>
  </div>
</aside>

<style>
  .sidebar-search-trigger {
    display: flex;
    align-items: center;
    gap: 6px;
    width: calc(100% - 24px);
    margin: 4px 12px 2px;
    padding: 6px 8px;
    background: var(--sidebar-bg-hover, rgba(255,255,255,0.06));
    border: none;
    border-radius: var(--radius-md);
    color: var(--sidebar-text-secondary);
    font-size: 13px;
    cursor: pointer;
    text-align: left;
    transition: background 0.1s, color 0.1s;
  }

  .sidebar-search-trigger:hover {
    background: var(--sidebar-bg-active, rgba(255,255,255,0.1));
    color: var(--sidebar-text);
  }

  .sidebar-search-icon {
    width: 13px;
    height: 13px;
    flex-shrink: 0;
    opacity: 0.6;
  }

  .sidebar-search-hint {
    flex: 1;
    min-width: 0;
    opacity: 0.6;
  }

  .sidebar-search-kbd {
    flex-shrink: 0;
    font-size: 10px;
    opacity: 0.4;
    font-family: inherit;
  }

  .sidebar-coll-group {
    position: relative;
  }

  .sidebar-coll-chevron {
    width: 14px;
    height: 14px;
    margin-left: auto;
    transition: transform 0.15s;
    opacity: 0.4;
    flex-shrink: 0;
  }

  .sidebar-coll-chevron.rotated {
    transform: rotate(180deg);
  }

  .sidebar-item:hover .sidebar-coll-chevron {
    opacity: 0.7;
  }

  /*
   * Sub-items: text aligns exactly with parent label.
   * Parent layout: 12px pad-left + 22px icon + 8px gap = 42px to text.
   * Sub-item uses the same 42px left padding so text lines up.
   * Right padding matches parent's 12px.
   */
  .sidebar-sub-items {
    padding: 2px 0 4px;
  }

  .sidebar-sub-item {
    display: flex;
    align-items: center;
    width: 100%;
    padding: 5px 12px 5px 42px;
    background: none;
    border: none;
    color: var(--sidebar-text-secondary);
    font-size: 14px;
    font-weight: 400;
    cursor: pointer;
    border-radius: var(--radius-md);
    text-align: left;
    transition: color 0.1s, background 0.1s;
  }

  .sidebar-sub-item:hover {
    color: var(--sidebar-text);
    background: var(--sidebar-bg-hover);
  }

  .sidebar-sub-item.sub-active {
    color: var(--sidebar-text);
    background: var(--sidebar-bg-active);
    font-weight: 500;
  }

  .sidebar-sub-text {
    flex: 1;
    min-width: 0;
  }

  .sidebar-sub-count {
    flex-shrink: 0;
    font-size: 12px;
    color: var(--sidebar-text-muted);
    tabular-nums: true;
    font-variant-numeric: tabular-nums;
    min-width: 20px;
    text-align: right;
  }

  .sidebar-sub-divider {
    height: 1px;
    background: var(--sidebar-border);
    margin: 4px 12px 4px 42px;
    opacity: 0.4;
  }
</style>
