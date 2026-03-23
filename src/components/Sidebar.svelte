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
    updateAvailable,
    rangerOpen,
    featureFlags,
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
  let hasUpdate = $derived($updateAvailable);
  let ff = $derived($featureFlags);
  function featureEnabled(key) {
    if (!ff) return true;
    return ff[key] !== false;
  }
  let filteredColls = $derived(
    grants === null ? colls : colls.filter(c => grants.includes(c.id))
  );
  let expandedColls = $state({});

  // Drawer state — which top-level section is open
  let activeSection = $state(null);

  // Pinned items — stored in localStorage
  let pinned = $state(JSON.parse(localStorage.getItem('outpost-pinned') || '[]'));

  function removePinned(index) {
    pinned = pinned.filter((_, i) => i !== index);
    localStorage.setItem('outpost-pinned', JSON.stringify(pinned));
  }

  // Visibility checks for groups
  let showContentGroup = $derived(
    (filteredColls.length > 0 && featureEnabled('collections')) ||
    featureEnabled('media')
  );
  let showSiteGroup = $derived(
    true // Globals is always visible
  );
  let showBuildGroup = $derived(
    (showCode && featureEnabled('code_editor')) ||
    (showFormBuilder && featureEnabled('forms')) ||
    (showChannels && featureEnabled('channels')) ||
    featureEnabled('collections')
  );
  let showToolsGroup = $derived(
    (showCode && featureEnabled('analytics')) ||
    showSettings
  );

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

  function nav(r, params = {}) {
    navigate(r, params);
    activeSection = null;
    if (window.innerWidth < 768) sidebarOpen.set(false);
  }

  function toggleSection(section) {
    activeSection = activeSection === section ? null : section;
  }

  function isSubActive(collSlug, status) {
    return route === 'collection-items' && activeCollSlug === collSlug && activeStatusFilter === status;
  }

  function isFolderSubActive(collId, fId) {
    return (route === 'folder-labels' || route === 'folder-label-edit') && activeFolderCollId === collId && activeFolderId === fId;
  }

  // Determine which section should be highlighted based on current route
  let activeSectionHighlight = $derived(() => {
    // Content routes
    if (route === 'collection-items' || route === 'collection-editor' || route === 'media') return 'content';
    // Site routes
    if (route === 'globals' || route === 'navigation' || route === 'themes' || route === 'theme-customizer' || route === 'brand' || route === 'lodge') return 'site';
    // Build routes
    if (route === 'code-editor' || route === 'template-reference' || route === 'forms-list' || route === 'form-builder' || route === 'channels' || route === 'channel-builder' || route === 'collections' || route === 'folder-manager') return 'build';
    // Tools routes
    if (route === 'analytics' || route?.startsWith?.('analytics-') || route === 'redirects' || route === 'review-tokens' || route === 'releases' || route === 'workflows') return 'tools';
    return null;
  });

  // Close drawer when clicking outside
  function handleOverlayClick() {
    activeSection = null;
  }

  // Section titles for drawer header
  function getSectionTitle(section) {
    const titles = { content: 'Content', site: 'Site', build: 'Build', tools: 'Tools' };
    return titles[section] || '';
  }
</script>

<aside class="sidebar" class:open={open}>
  <!-- Main sidebar panel -->
  <div class="sidebar-main">
    <div class="sidebar-logo sidebar-inner">
      <img src={outpostLogo} alt="Outpost" style="height: 11px; width: auto; filter: brightness(0) invert(1);" />
    </div>

    <button class="sidebar-search-trigger sidebar-inner" onclick={() => searchOpen.set(true)}>
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="sidebar-search-icon">
        <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
      </svg>
      <span class="sidebar-search-hint">Search...</span>
      <span class="sidebar-search-kbd">&#8984;K</span>
    </button>

    <!-- Dashboard + Inbox (always visible, navigate directly) -->
    <div class="sidebar-section sidebar-inner">
      <button
        class="sidebar-item"
        class:active={route === 'dashboard'}
        onclick={() => nav('dashboard')}
      >
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="12" r="10"/><polygon points="16.24 7.76 14.12 14.12 7.76 16.24 9.88 9.88 16.24 7.76"/></svg>
        Dashboard
      </button>
      {#if featureEnabled('forms')}
        <button
          class="sidebar-item"
          class:active={route === 'forms' || route === 'form-submissions'}
          onclick={() => nav('form-submissions')}
        >
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
          Inbox
        </button>
      {/if}
    </div>

    <!-- Pinned items -->
    {#if pinned.length > 0}
      <div class="sidebar-section sidebar-inner">
        {#each pinned as pin, i (i)}
          <button
            class="sidebar-item"
            onclick={() => nav(pin.route, pin.params || {})}
          >
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
            {pin.label}
            <span class="pin-remove" role="button" tabindex="0" onclick={(e) => { e.stopPropagation(); removePinned(i); }} onkeydown={(e) => { if (e.key === 'Enter') { e.stopPropagation(); removePinned(i); } }} title="Unpin">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="10" height="10"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </span>
          </button>
        {/each}
      </div>
    {/if}

    <div class="sidebar-divider"></div>

    <!-- Section triggers -->
    {#if showContentGroup}
      <button
        class="sidebar-section-trigger"
        class:active={activeSection === 'content'}
        class:section-highlight={activeSectionHighlight() === 'content' && activeSection !== 'content'}
        onclick={() => toggleSection('content')}
      >
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M9 18a2 2 0 01-2-2V4a2 2 0 012-2h7l4 4v10a2 2 0 01-2 2z"/><path d="M15 2v6h6"/><path d="M3 8v12a2 2 0 002 2h10"/></svg>
        Content
        <svg class="trigger-chevron" class:rotated={activeSection === 'content'} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 6 15 12 9 18"/></svg>
      </button>
    {/if}

    {#if showSiteGroup}
      <button
        class="sidebar-section-trigger"
        class:active={activeSection === 'site'}
        class:section-highlight={activeSectionHighlight() === 'site' && activeSection !== 'site'}
        onclick={() => toggleSection('site')}
      >
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 014 10 15.3 15.3 0 01-4 10 15.3 15.3 0 01-4-10 15.3 15.3 0 014-10z"/></svg>
        Site
        <svg class="trigger-chevron" class:rotated={activeSection === 'site'} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 6 15 12 9 18"/></svg>
      </button>
    {/if}

    {#if showBuildGroup}
      <button
        class="sidebar-section-trigger"
        class:active={activeSection === 'build'}
        class:section-highlight={activeSectionHighlight() === 'build' && activeSection !== 'build'}
        onclick={() => toggleSection('build')}
      >
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>
        Build
        <svg class="trigger-chevron" class:rotated={activeSection === 'build'} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 6 15 12 9 18"/></svg>
      </button>
    {/if}

    {#if showToolsGroup}
      <button
        class="sidebar-section-trigger"
        class:active={activeSection === 'tools'}
        class:section-highlight={activeSectionHighlight() === 'tools' && activeSection !== 'tools'}
        onclick={() => toggleSection('tools')}
      >
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14.7 6.3a1 1 0 000 1.4l1.6 1.6a1 1 0 001.4 0l3.77-3.77a6 6 0 01-7.94 7.94l-6.91 6.91a2.12 2.12 0 01-3-3l6.91-6.91a6 6 0 017.94-7.94l-3.76 3.76z"/></svg>
        Tools
        <svg class="trigger-chevron" class:rotated={activeSection === 'tools'} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 6 15 12 9 18"/></svg>
      </button>
    {/if}

    <!-- Spacer pushes Ranger to bottom -->
    <div class="sidebar-spacer"></div>

    <!-- Ranger (bottom, always visible) -->
    <div class="sidebar-section sidebar-inner sidebar-bottom">
      {#if featureEnabled('ranger')}
        <button
          class="sidebar-item ranger-item"
          class:active={$rangerOpen}
          onclick={() => rangerOpen.update(v => !v)}
          title="Ranger AI Assistant"
        >
          <svg viewBox="0 0 24 24" fill="currentColor" width="16" height="16">
            <path d="M10 2L11.5 7.5L17 9L11.5 10.5L10 16L8.5 10.5L3 9L8.5 7.5L10 2Z"/>
            <path d="M18 12L19 15L22 16L19 17L18 20L17 17L14 16L17 15L18 12Z" opacity="0.6"/>
          </svg>
          Ranger
        </button>
      {/if}
    </div>
  </div>

  <!-- Drawer panel (slides out to the right of the sidebar) -->
  <div class="sidebar-drawer" class:drawer-open={activeSection !== null}>
    {#if activeSection}
      <div class="drawer-header">
        <span class="drawer-title">{getSectionTitle(activeSection)}</span>
        <button class="drawer-close" onclick={() => activeSection = null} title="Close">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="14" height="14"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
      </div>
      <div class="drawer-items">
        <!-- ═══ CONTENT DRAWER ═══ -->
        {#if activeSection === 'content'}
          {#if filteredColls.length > 0 && featureEnabled('collections')}
            {#each filteredColls as coll}
              {@const stats = getCollStats(coll.slug)}
              {@const isExpanded = expandedColls[coll.slug]}
              <div class="drawer-coll-group">
                <button
                  class="drawer-item"
                  class:active={route === 'collection-items' && activeCollSlug === coll.slug && activeStatusFilter === 'all'}
                  onclick={() => toggleCollExpand(coll.slug)}
                >
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M9 18a2 2 0 01-2-2V4a2 2 0 012-2h7l4 4v10a2 2 0 01-2 2z"/><path d="M15 2v6h6"/><path d="M3 8v12a2 2 0 002 2h10"/></svg>
                  {coll.name}
                  <svg class="drawer-coll-chevron" class:rotated={isExpanded} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
                </button>
                {#if isExpanded}
                  <div class="drawer-sub-items">
                    <button class="drawer-sub-item" class:sub-active={isSubActive(coll.slug, 'all')} onclick={() => nav('collection-items', { collectionSlug: coll.slug, statusFilter: 'all' })}>
                      <span class="drawer-sub-text">All Items</span><span class="drawer-sub-count">{stats?.item_count ?? coll.item_count ?? 0}</span>
                    </button>
                    <button class="drawer-sub-item" class:sub-active={isSubActive(coll.slug, 'draft')} onclick={() => nav('collection-items', { collectionSlug: coll.slug, statusFilter: 'draft' })}>
                      <span class="drawer-sub-text">Drafts</span><span class="drawer-sub-count">{stats?.draft_count ?? coll.draft_count ?? 0}</span>
                    </button>
                    {#if coll.require_review}
                      <button class="drawer-sub-item" class:sub-active={isSubActive(coll.slug, 'pending_review')} onclick={() => nav('collection-items', { collectionSlug: coll.slug, statusFilter: 'pending_review' })}>
                        <span class="drawer-sub-text">Pending</span><span class="drawer-sub-count">{stats?.pending_count ?? coll.pending_count ?? 0}</span>
                      </button>
                    {/if}
                    <button class="drawer-sub-item" class:sub-active={isSubActive(coll.slug, 'scheduled')} onclick={() => nav('collection-items', { collectionSlug: coll.slug, statusFilter: 'scheduled' })}>
                      <span class="drawer-sub-text">Scheduled</span><span class="drawer-sub-count">{stats?.scheduled_count ?? coll.scheduled_count ?? 0}</span>
                    </button>
                    <button class="drawer-sub-item" class:sub-active={isSubActive(coll.slug, 'published')} onclick={() => nav('collection-items', { collectionSlug: coll.slug, statusFilter: 'published' })}>
                      <span class="drawer-sub-text">Published</span><span class="drawer-sub-count">{stats?.published_count ?? coll.published_count ?? 0}</span>
                    </button>
                    {#if stats?.taxonomies?.length}
                      <div class="drawer-sub-divider"></div>
                      {#each stats.taxonomies as tax}
                        <button class="drawer-sub-item" class:sub-active={isFolderSubActive(coll.id, tax.id)} onclick={() => nav('folder-labels', { folderCollectionId: coll.id, folderId: tax.id })}>
                          <span class="drawer-sub-text">{tax.name}</span>
                        </button>
                      {/each}
                    {/if}
                  </div>
                {/if}
              </div>
            {/each}
          {/if}
          {#if featureEnabled('media')}
            <button
              class="drawer-item"
              class:active={route === 'media'}
              onclick={() => nav('media')}
            >
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
              Media
            </button>
          {/if}

        <!-- ═══ SITE DRAWER ═══ -->
        {:else if activeSection === 'site'}
          <button
            class="drawer-item"
            class:active={route === 'globals'}
            onclick={() => nav('globals')}
          >
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 014 10 15.3 15.3 0 01-4 10 15.3 15.3 0 01-4-10 15.3 15.3 0 014-10z"/></svg>
            Globals
          </button>
          {#if featureEnabled('navigation')}
            <button
              class="drawer-item"
              class:active={route === 'navigation'}
              onclick={() => nav('navigation')}
            >
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
              Navigation
            </button>
          {/if}
          {#if showSettings}
            <button
              class="drawer-item"
              class:active={route === 'themes' || route === 'theme-customizer'}
              onclick={() => nav('themes')}
            >
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>
              Themes
            </button>
            <button
              class="drawer-item"
              class:active={route === 'brand'}
              onclick={() => nav('brand')}
            >
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M2 12c0-5.523 4.477-10 10-10s10 4.477 10 10-4.477 10-10 10c-1.657 0-3-1.343-3-3v-.286c0-.564 0-.846-.072-1.082a2 2 0 00-1.274-1.274c-.236-.072-.518-.072-1.082-.072H9.5A4.5 4.5 0 015 12.5c0-1.38-.62-2.613-1.595-3.437"/><circle cx="7.5" cy="10" r="1"/><circle cx="12" cy="7.5" r="1"/><circle cx="16.5" cy="10" r="1"/></svg>
              Brand
            </button>
          {/if}
          {#if showMembers && featureEnabled('members')}
            <button
              class="drawer-item"
              class:active={route === 'settings' && false}
              onclick={() => nav('settings', { section: 'members' })}
            >
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg>
              Members
            </button>
          {/if}
          {#if featureEnabled('lodge')}
            <button
              class="drawer-item"
              class:active={route === 'lodge'}
              onclick={() => nav('lodge')}
            >
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
              Lodge
            </button>
          {/if}

        <!-- ═══ BUILD DRAWER ═══ -->
        {:else if activeSection === 'build'}
          {#if showCode && featureEnabled('code_editor')}
            <button
              class="drawer-item"
              class:active={route === 'code-editor' || route === 'template-reference'}
              onclick={() => nav('code-editor')}
            >
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>
              Code Editor
            </button>
          {/if}
          {#if showFormBuilder && featureEnabled('forms')}
            <button
              class="drawer-item"
              class:active={route === 'forms-list' || route === 'form-builder'}
              onclick={() => nav('forms-list')}
            >
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M9 11H3v10h6V11z"/><path d="M21 3H3v6h18V3z"/><path d="M21 11h-6v10h6V11z"/></svg>
              Forms
            </button>
          {/if}
          {#if showChannels && featureEnabled('channels')}
            <button
              class="drawer-item"
              class:active={route === 'channels' || route === 'channel-builder'}
              onclick={() => nav('channels')}
            >
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M4.9 19.1C1 15.2 1 8.8 4.9 4.9"/><path d="M7.8 16.2c-2.3-2.3-2.3-6.1 0-8.4"/><circle cx="12" cy="12" r="2"/><path d="M16.2 7.8c2.3 2.3 2.3 6.1 0 8.4"/><path d="M19.1 4.9C23 8.8 23 15.2 19.1 19.1"/></svg>
              Channels
            </button>
          {/if}
          {#if featureEnabled('collections')}
            <button
              class="drawer-item"
              class:active={route === 'collections'}
              onclick={() => nav('collections')}
            >
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
              Collections
            </button>
          {/if}
          <button
            class="drawer-item"
            class:active={route === 'folder-manager'}
            onclick={() => nav('folder-manager')}
          >
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M22 19a2 2 0 01-2 2H4a2 2 0 01-2-2V5a2 2 0 012-2h5l2 3h9a2 2 0 012 2z"/></svg>
            Folders
          </button>
          {#if showSettings && featureEnabled('releases')}
            <button
              class="drawer-item"
              class:active={route === 'releases'}
              onclick={() => nav('releases')}
            >
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
              Releases
            </button>
          {/if}
          {#if showSettings && featureEnabled('workflows')}
            <button
              class="drawer-item"
              class:active={route === 'workflows'}
              onclick={() => nav('workflows')}
            >
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
              Workflows
            </button>
          {/if}

        <!-- ═══ TOOLS DRAWER ═══ -->
        {:else if activeSection === 'tools'}
          {#if showCode && featureEnabled('analytics')}
            <button
              class="drawer-item"
              class:active={route === 'analytics' || route.startsWith('analytics-')}
              onclick={() => nav('analytics')}
            >
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
              Analytics
            </button>
          {/if}
          {#if showSettings}
            <button
              class="drawer-item"
              class:active={route === 'redirects'}
              onclick={() => nav('redirects')}
            >
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><polyline points="9 14 4 9 9 4"/><path d="M20 20v-7a4 4 0 00-4-4H4"/></svg>
              Redirects
            </button>
          {/if}
          {#if showSettings && featureEnabled('shield')}
            <button
              class="drawer-item"
              class:active={route === 'settings' && false}
              onclick={() => nav('settings', { section: 'shield' })}
            >
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
              Shield
            </button>
          {/if}
          {#if showSettings && featureEnabled('boost')}
            <button
              class="drawer-item"
              class:active={route === 'settings' && false}
              onclick={() => nav('settings', { section: 'boost' })}
            >
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
              Boost
            </button>
          {/if}
          {#if showSettings && featureEnabled('review_links')}
            <button
              class="drawer-item"
              class:active={route === 'review-tokens'}
              onclick={() => nav('review-tokens')}
            >
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M10 13a5 5 0 007.54.54l3-3a5 5 0 00-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 00-7.54-.54l-3 3a5 5 0 007.07 7.07l1.71-1.71"/></svg>
              Review Links
            </button>
          {/if}
        {/if}
      </div>
    {/if}
  </div>
</aside>

<!-- Overlay to close drawer when clicking outside -->
{#if activeSection}
  <div class="drawer-overlay" onclick={handleOverlayClick} role="presentation"></div>
{/if}

<style>
  /* ── Main sidebar panel ── */
  .sidebar-main {
    width: var(--sidebar-width);
    height: 100%;
    display: flex;
    flex-direction: column;
    position: relative;
    z-index: 3;
  }

  /* ── Search trigger ── */
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

  /* ── Section triggers (top-level expandable items) ── */
  .sidebar-section-trigger {
    display: flex;
    align-items: center;
    gap: var(--space-sm, 8px);
    width: calc(100% - 16px);
    margin: 1px 8px;
    padding: 8px 12px;
    background: none;
    border: none;
    border-radius: var(--radius-md, 6px);
    color: var(--sidebar-text-secondary, rgba(255,255,255,0.7));
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    text-align: left;
    transition: all 0.15s ease;
    position: relative;
  }

  .sidebar-section-trigger:hover {
    background: var(--sidebar-bg-hover);
    color: var(--sidebar-text);
  }

  .sidebar-section-trigger.active {
    background: var(--sidebar-bg-active);
    color: var(--sidebar-text);
  }

  .sidebar-section-trigger.section-highlight {
    color: var(--sidebar-text);
  }

  .sidebar-section-trigger.section-highlight::before {
    content: '';
    position: absolute;
    left: 0;
    top: 50%;
    transform: translateY(-50%);
    width: 3px;
    height: 16px;
    background: var(--accent, #4ade80);
    border-radius: 0 2px 2px 0;
  }

  .sidebar-section-trigger svg:first-child {
    width: 18px;
    height: 18px;
    flex-shrink: 0;
    opacity: 0.6;
  }

  .sidebar-section-trigger:hover svg:first-child,
  .sidebar-section-trigger.active svg:first-child {
    opacity: 1;
  }

  .trigger-chevron {
    width: 12px;
    height: 12px;
    margin-left: auto;
    flex-shrink: 0;
    opacity: 0.3;
    transition: transform 0.2s ease, opacity 0.15s;
  }

  .sidebar-section-trigger:hover .trigger-chevron {
    opacity: 0.6;
  }

  .trigger-chevron.rotated {
    transform: rotate(90deg);
  }

  /* ── Drawer panel ── */
  .sidebar-drawer {
    position: fixed;
    top: 0;
    left: var(--sidebar-width);
    width: 260px;
    height: 100vh;
    background: #2E2C29;
    border-right: 1px solid var(--sidebar-border);
    box-shadow: 4px 0 24px rgba(0, 0, 0, 0.3);
    z-index: 2;
    overflow-y: auto;
    scrollbar-width: none;
    -ms-overflow-style: none;
    transform: translateX(-100%);
    opacity: 0;
    transition: transform 0.2s ease, opacity 0.2s ease;
    pointer-events: none;
  }

  .sidebar-drawer::-webkit-scrollbar {
    display: none;
  }

  .sidebar-drawer.drawer-open {
    transform: translateX(0);
    opacity: 1;
    pointer-events: auto;
  }

  :root.dark .sidebar-drawer {
    background: #1a1a1e;
  }

  .drawer-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 16px 16px 12px;
    border-bottom: 1px solid var(--sidebar-border);
  }

  .drawer-title {
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: var(--sidebar-text-muted, rgba(255,255,255,0.35));
  }

  .drawer-close {
    background: none;
    border: none;
    padding: 4px;
    border-radius: var(--radius-sm, 4px);
    color: var(--sidebar-text-muted, rgba(255,255,255,0.35));
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: color 0.15s, background 0.15s;
  }

  .drawer-close:hover {
    color: var(--sidebar-text);
    background: var(--sidebar-bg-hover);
  }

  .drawer-items {
    padding: 8px;
  }

  /* ── Drawer item styles ── */
  .drawer-item {
    display: flex;
    align-items: center;
    gap: var(--space-sm, 8px);
    padding: 7px 12px;
    border-radius: var(--radius-md, 6px);
    color: var(--sidebar-text-secondary);
    cursor: pointer;
    transition: all var(--transition-fast, 0.1s);
    font-size: 14px;
    font-weight: 500;
    border: none;
    background: none;
    width: 100%;
    text-align: left;
  }

  .drawer-item:hover {
    background: var(--sidebar-bg-hover);
    color: var(--sidebar-text);
  }

  .drawer-item.active {
    background: var(--sidebar-bg-active);
    color: var(--sidebar-text);
  }

  .drawer-item svg {
    width: 18px;
    height: 18px;
    stroke-width: 1.5;
    opacity: 0.6;
    flex-shrink: 0;
  }

  .drawer-item:hover svg,
  .drawer-item.active svg {
    opacity: 1;
  }

  /* ── Collection expand in drawer ── */
  .drawer-coll-group {
    position: relative;
  }

  .drawer-coll-chevron {
    width: 14px;
    height: 14px;
    margin-left: auto;
    transition: transform 0.15s;
    opacity: 0.4;
    flex-shrink: 0;
  }

  .drawer-coll-chevron.rotated {
    transform: rotate(180deg);
  }

  .drawer-item:hover .drawer-coll-chevron {
    opacity: 0.7;
  }

  .drawer-sub-items {
    padding: 2px 0 4px;
  }

  .drawer-sub-item {
    display: flex;
    align-items: center;
    width: 100%;
    padding: 5px 12px 5px 40px;
    background: none;
    border: none;
    color: var(--sidebar-text-secondary);
    font-size: 13px;
    font-weight: 400;
    cursor: pointer;
    border-radius: var(--radius-md);
    text-align: left;
    transition: color 0.1s, background 0.1s;
  }

  .drawer-sub-item:hover {
    color: var(--sidebar-text);
    background: var(--sidebar-bg-hover);
  }

  .drawer-sub-item.sub-active {
    color: var(--sidebar-text);
    background: var(--sidebar-bg-active);
    font-weight: 500;
  }

  .drawer-sub-text {
    flex: 1;
    min-width: 0;
  }

  .drawer-sub-count {
    flex-shrink: 0;
    font-size: 12px;
    color: var(--sidebar-text-muted);
    font-variant-numeric: tabular-nums;
    min-width: 20px;
    text-align: right;
  }

  .drawer-sub-divider {
    height: 1px;
    background: var(--sidebar-border);
    margin: 4px 12px 4px 40px;
    opacity: 0.4;
  }

  /* ── Overlay (closes drawer on outside click) ── */
  .drawer-overlay {
    position: fixed;
    inset: 0;
    z-index: 1;
    background: rgba(0, 0, 0, 0.15);
  }

  /* ── Bottom / spacer ── */
  .sidebar-spacer {
    flex: 1;
  }

  .sidebar-bottom {
    margin-top: 0;
    padding-bottom: 8px;
  }

  .ranger-item svg {
    width: 16px;
    height: 16px;
  }

  /* ── Pin remove ── */
  .pin-remove {
    margin-left: auto;
    background: none;
    border: none;
    padding: 2px;
    cursor: pointer;
    color: var(--sidebar-text-muted);
    opacity: 0;
    transition: opacity 0.1s;
    display: flex;
    align-items: center;
  }

  .sidebar-item:hover .pin-remove {
    opacity: 1;
  }

  .pin-remove:hover {
    color: var(--sidebar-text);
  }

  /* ── Mobile: hide drawer ── */
  @media (max-width: 768px) {
    .sidebar-drawer {
      display: none;
    }
    .drawer-overlay {
      display: none;
    }
  }
</style>
