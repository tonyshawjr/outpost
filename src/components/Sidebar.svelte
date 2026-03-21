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

  // Accordion group state — persisted to localStorage
  let groupState = $state(JSON.parse(localStorage.getItem('outpost-sidebar-groups') || '{}'));

  function isGroupOpen(key) {
    return groupState[key] !== false; // default open
  }

  function toggleGroup(key) {
    groupState = { ...groupState, [key]: !isGroupOpen(key) };
    localStorage.setItem('outpost-sidebar-groups', JSON.stringify(groupState));
  }

  // Visibility checks for groups — hide groups with zero visible items
  let showContentGroup = $derived(
    (filteredColls.length > 0 && featureEnabled('collections')) ||
    (showChannels && featureEnabled('channels')) ||
    featureEnabled('media')
  );
  let showSiteGroup = $derived(
    true // Globals is always visible
  );
  let showBuildGroup = $derived(
    showSettings || (showCode && featureEnabled('code_editor'))
  );
  let showMembersGroup = $derived(
    (showMembers && featureEnabled('members')) || featureEnabled('lodge')
  );
  let showInsightsGroup = $derived(
    showCode && featureEnabled('analytics')
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

  <!-- Dashboard (always visible, no group) -->
  <div class="sidebar-section sidebar-inner">
    <button
      class="sidebar-item"
      class:active={route === 'dashboard'}
      onclick={() => nav('dashboard')}
    >
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="12" r="10"/><polygon points="16.24 7.76 14.12 14.12 7.76 16.24 9.88 9.88 16.24 7.76"/></svg>
      Dashboard
    </button>
    <button
      class="sidebar-item"
      class:active={route === 'calendar'}
      onclick={() => nav('calendar')}
    >
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
      Calendar
    </button>
  </div>

  <!-- ═══ CONTENT ═══ -->
  {#if showContentGroup}
    <div class="sidebar-divider"></div>
    <div class="sidebar-section sidebar-inner">
      <button class="sidebar-group-label" onclick={() => toggleGroup('content')}>
        Content
        <svg class="sidebar-group-chevron" class:rotated={isGroupOpen('content')} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
      </button>
      {#if isGroupOpen('content')}
        {#if filteredColls.length > 0 && featureEnabled('collections')}
          {#each filteredColls as coll}
            {@const stats = getCollStats(coll.slug)}
            {@const isExpanded = expandedColls[coll.slug]}
            <div class="sidebar-coll-group">
              <button
                class="sidebar-item"
                class:active={route === 'collection-items' && activeCollSlug === coll.slug && activeStatusFilter === 'all'}
                onclick={() => toggleCollExpand(coll.slug)}
              >
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M9 18a2 2 0 01-2-2V4a2 2 0 012-2h7l4 4v10a2 2 0 01-2 2z"/><path d="M15 2v6h6"/><path d="M3 8v12a2 2 0 002 2h10"/></svg>
                {coll.name}
                <svg class="sidebar-coll-chevron" class:rotated={isExpanded} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
              </button>
              {#if isExpanded}
                <div class="sidebar-sub-items">
                  <button class="sidebar-sub-item" class:sub-active={isSubActive(coll.slug, 'all')} onclick={() => nav('collection-items', { collectionSlug: coll.slug, statusFilter: 'all' })}>
                    <span class="sidebar-sub-text">All Items</span><span class="sidebar-sub-count">{stats?.item_count ?? coll.item_count ?? 0}</span>
                  </button>
                  <button class="sidebar-sub-item" class:sub-active={isSubActive(coll.slug, 'draft')} onclick={() => nav('collection-items', { collectionSlug: coll.slug, statusFilter: 'draft' })}>
                    <span class="sidebar-sub-text">Drafts</span><span class="sidebar-sub-count">{stats?.draft_count ?? coll.draft_count ?? 0}</span>
                  </button>
                  {#if coll.require_review}
                    <button class="sidebar-sub-item" class:sub-active={isSubActive(coll.slug, 'pending_review')} onclick={() => nav('collection-items', { collectionSlug: coll.slug, statusFilter: 'pending_review' })}>
                      <span class="sidebar-sub-text">Pending</span><span class="sidebar-sub-count">{stats?.pending_count ?? coll.pending_count ?? 0}</span>
                    </button>
                  {/if}
                  <button class="sidebar-sub-item" class:sub-active={isSubActive(coll.slug, 'scheduled')} onclick={() => nav('collection-items', { collectionSlug: coll.slug, statusFilter: 'scheduled' })}>
                    <span class="sidebar-sub-text">Scheduled</span><span class="sidebar-sub-count">{stats?.scheduled_count ?? coll.scheduled_count ?? 0}</span>
                  </button>
                  <button class="sidebar-sub-item" class:sub-active={isSubActive(coll.slug, 'published')} onclick={() => nav('collection-items', { collectionSlug: coll.slug, statusFilter: 'published' })}>
                    <span class="sidebar-sub-text">Published</span><span class="sidebar-sub-count">{stats?.published_count ?? coll.published_count ?? 0}</span>
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
        {/if}
        {#if showChannels && featureEnabled('channels')}
          <button
            class="sidebar-item"
            class:active={route === 'channels' || route === 'channel-builder'}
            onclick={() => nav('channels')}
          >
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M4.9 19.1C1 15.2 1 8.8 4.9 4.9"/><path d="M7.8 16.2c-2.3-2.3-2.3-6.1 0-8.4"/><circle cx="12" cy="12" r="2"/><path d="M16.2 7.8c2.3 2.3 2.3 6.1 0 8.4"/><path d="M19.1 4.9C23 8.8 23 15.2 19.1 19.1"/></svg>
            Channels
          </button>
        {/if}
        {#if featureEnabled('media')}
          <button
            class="sidebar-item"
            class:active={route === 'media'}
            onclick={() => nav('media')}
          >
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
            Media
          </button>
        {/if}
      {/if}
    </div>
  {/if}

  <!-- ═══ SITE ═══ -->
  {#if showSiteGroup}
    <div class="sidebar-divider"></div>
    <div class="sidebar-section sidebar-inner">
      <button class="sidebar-group-label" onclick={() => toggleGroup('site')}>
        Site
        <svg class="sidebar-group-chevron" class:rotated={isGroupOpen('site')} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
      </button>
      {#if isGroupOpen('site')}
        <button
          class="sidebar-item"
          class:active={route === 'globals'}
          onclick={() => nav('globals')}
        >
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 014 10 15.3 15.3 0 01-4 10 15.3 15.3 0 01-4-10 15.3 15.3 0 014-10z"/></svg>
          Globals
        </button>
        {#if featureEnabled('navigation')}
          <button
            class="sidebar-item"
            class:active={route === 'navigation'}
            onclick={() => nav('navigation')}
          >
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
            Navigation
          </button>
        {/if}
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
      {/if}
    </div>
  {/if}

  <!-- ═══ MEMBERS ═══ -->
  {#if showMembersGroup}
    <div class="sidebar-divider"></div>
    <div class="sidebar-section sidebar-inner">
      <button class="sidebar-group-label" onclick={() => toggleGroup('members')}>
        Members
        <svg class="sidebar-group-chevron" class:rotated={isGroupOpen('members')} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
      </button>
      {#if isGroupOpen('members')}
        {#if showMembers && featureEnabled('members')}
          <button
            class="sidebar-item"
            class:active={route === 'settings' && false}
            onclick={() => nav('settings', { section: 'members' })}
          >
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg>
            Members
          </button>
        {/if}
        {#if featureEnabled('lodge')}
          <button
            class="sidebar-item"
            class:active={false}
            onclick={() => nav('settings', { section: 'features' })}
          >
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
            Lodge
          </button>
        {/if}
      {/if}
    </div>
  {/if}

  <!-- ═══ BUILD ═══ -->
  {#if showBuildGroup}
    <div class="sidebar-divider"></div>
    <div class="sidebar-section sidebar-inner">
      <button class="sidebar-group-label" onclick={() => toggleGroup('build')}>
        Build
        <svg class="sidebar-group-chevron" class:rotated={isGroupOpen('build')} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
      </button>
      {#if isGroupOpen('build')}
        {#if showSettings}
          <button
            class="sidebar-item"
            class:active={route === 'themes' || route === 'theme-customizer'}
            onclick={() => nav('themes')}
          >
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>
            Themes
          </button>
          <button
            class="sidebar-item"
            class:active={route === 'brand'}
            onclick={() => nav('brand')}
          >
            <svg viewBox="0 0 324.99 324.99" fill="currentColor"><path d="M307.6,129.885c-11.453-11.447-23.783-16.778-38.805-16.778c-6.189,0-12.056,0.858-17.729,1.688c-5.094,0.745-9.905,1.449-14.453,1.45c-8.27,0-14.197-2.397-19.82-8.017c-10.107-10.101-8.545-20.758-6.569-34.25c2.357-16.096,5.291-36.127-15.101-56.508C183.578,5.932,167.848,0.081,148.372,0.081c-37.296,0-78.367,21.546-99.662,42.829C17.398,74.205,0.1,115.758,0,159.917c-0.1,44.168,17.018,85.656,48.199,116.82c31.077,31.061,72.452,48.168,116.504,48.171c0.005,0,0.007,0,0.013,0c44.315,0,86.02-17.289,117.428-48.681c17.236-17.226,32.142-44.229,38.9-70.471C329.291,173.738,324.517,146.793,307.6,129.885z M309.424,202.764c-6.16,23.915-20.197,49.42-35.763,64.976c-29.145,29.129-67.833,45.17-108.946,45.169c-0.002,0-0.009,0-0.011,0c-40.849-0.003-79.211-15.863-108.023-44.659C27.777,239.36,11.908,200.896,12,159.944c0.092-40.962,16.142-79.512,45.191-108.545c19.071-19.061,57.508-39.317,91.18-39.317c16.18,0,29.056,4.669,38.269,13.877c16.127,16.118,13.981,30.769,11.71,46.28c-2.067,14.116-4.41,30.115,9.96,44.478c7.871,7.866,16.864,11.529,28.304,11.528c5.421-0.001,10.895-0.802,16.189-1.576c5.248-0.768,10.676-1.562,15.992-1.562c7.938,0,18.557,1.508,30.322,13.267C317.724,156.971,313.562,186.699,309.424,202.764z"/><path d="M142.002,43.531c-1.109,0-2.233,0.065-3.342,0.192c-15.859,1.824-27.33,16.199-25.571,32.042c1.613,14.631,13.93,25.665,28.647,25.665c1.105,0,2.226-0.065,3.332-0.191c15.851-1.823,27.326-16.191,25.581-32.031C169.032,54.57,156.716,43.531,142.002,43.531z"/><path d="M102.997,113.64c-1.72-7.512-6.261-13.898-12.784-17.984c-4.597-2.881-9.889-4.404-15.304-4.404c-10.051,0-19.254,5.079-24.618,13.587c-4.14,6.566-5.472,14.34-3.75,21.888c1.715,7.52,6.261,13.92,12.799,18.018c4.596,2.88,9.888,4.402,15.303,4.402c10.051,0,19.255-5.078,24.624-13.593C103.401,128.975,104.726,121.193,102.997,113.64z"/><path d="M70.131,173.25c-3.275,0-6.516,0.557-9.63,1.654c-15.055,5.301-23.05,21.849-17.821,36.892c4.032,11.579,14.984,19.358,27.254,19.358c3.276,0,6.517-0.556,9.637-1.652c15.065-5.301,23.053-21.854,17.806-36.896C93.346,181.029,82.397,173.25,70.131,173.25z"/><path d="M140.817,229.415c-3.071-1.066-6.266-1.606-9.496-1.606c-12.307,0-23.328,7.804-27.431,19.429c-2.566,7.317-2.131,15.185,1.229,22.151c3.349,6.943,9.204,12.163,16.486,14.696c3.075,1.071,6.274,1.614,9.51,1.614c12.3,0,23.314-7.811,27.409-19.439c2.574-7.31,2.143-15.175-1.216-22.145C153.958,237.165,148.103,231.945,140.817,229.415z"/><path d="M212.332,213.811c-5.466,0-10.81,1.55-15.448,4.479c-13.525,8.521-17.652,26.427-9.193,39.927c5.315,8.445,14.463,13.488,24.469,13.488c5.458,0,10.796-1.545,15.434-4.464c13.541-8.507,17.663-26.419,9.19-39.926C231.486,218.86,222.345,213.811,212.332,213.811z"/><path d="M255.384,141.998c-1.06-0.117-2.134-0.176-3.194-0.176c-14.772,0-27.174,11.068-28.846,25.747c-0.876,7.698,1.297,15.266,6.118,21.311c4.812,6.03,11.686,9.821,19.369,10.676c1.053,0.114,2.12,0.173,3.175,0.173c14.754,0,27.164-11.067,28.869-25.748c0.886-7.688-1.277-15.247-6.091-21.288C269.97,146.651,263.082,142.853,255.384,141.998z"/></svg>
            Brand
          </button>
        {/if}
        {#if showCode && featureEnabled('code_editor')}
          <button
            class="sidebar-item"
            class:active={route === 'code-editor' || route === 'template-reference'}
            onclick={() => nav('code-editor')}
          >
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>
            Code Editor
          </button>
        {/if}
        {#if featureEnabled('collections')}
          <button
            class="sidebar-item"
            class:active={route === 'collections'}
            onclick={() => nav('collections')}
          >
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
            Collections
          </button>
        {/if}
        {#if showFormBuilder && featureEnabled('forms')}
          <button
            class="sidebar-item"
            class:active={route === 'forms-list' || route === 'form-builder'}
            onclick={() => nav('forms-list')}
          >
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M9 11H3v10h6V11z"/><path d="M21 3H3v6h18V3z"/><path d="M21 11h-6v10h6V11z"/></svg>
            Form Builder
          </button>
        {/if}
        <button
          class="sidebar-item"
          class:active={route === 'folder-manager'}
          onclick={() => nav('folder-manager')}
        >
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M22 19a2 2 0 01-2 2H4a2 2 0 01-2-2V5a2 2 0 012-2h5l2 3h9a2 2 0 012 2z"/></svg>
          Folders
        </button>
        {#if showSettings && featureEnabled('releases')}
          <button
            class="sidebar-item"
            class:active={route === 'releases'}
            onclick={() => nav('releases')}
          >
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
            Releases
          </button>
        {/if}
        {#if showSettings && featureEnabled('workflows')}
          <button
            class="sidebar-item"
            class:active={route === 'workflows'}
            onclick={() => nav('workflows')}
          >
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
            Workflows
          </button>
        {/if}
      {/if}
    </div>
  {/if}

  <!-- ═══ INSIGHTS ═══ -->
  {#if showInsightsGroup}
    <div class="sidebar-divider"></div>
    <div class="sidebar-section sidebar-inner">
      <button class="sidebar-group-label" onclick={() => toggleGroup('insights')}>
        Insights
        <svg class="sidebar-group-chevron" class:rotated={isGroupOpen('insights')} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
      </button>
      {#if isGroupOpen('insights')}
        <button
          class="sidebar-item"
          class:active={route === 'analytics' || route.startsWith('analytics-')}
          onclick={() => nav('analytics')}
        >
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
          Analytics
        </button>
      {/if}
    </div>
  {/if}

  <!-- ═══ SYSTEM (bottom, always visible) ═══ -->
  <div class="sidebar-divider"></div>
  <div class="sidebar-section sidebar-inner" style="margin-top: auto;">
    {#if showSettings && featureEnabled('review_links')}
      <button
        class="sidebar-item"
        class:active={route === 'review-tokens'}
        onclick={() => nav('review-tokens')}
      >
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M10 13a5 5 0 007.54.54l3-3a5 5 0 00-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 00-7.54-.54l-3 3a5 5 0 007.07 7.07l1.71-1.71"/></svg>
        Review Links
      </button>
    {/if}
    {#if showSettings && featureEnabled('backups')}
      <button
        class="sidebar-item"
        class:active={route === 'backups'}
        onclick={() => nav('backups')}
      >
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M21 8v13H3V8"/><path d="M1 3h22v5H1z"/><path d="M10 12h4"/></svg>
        Backups
      </button>
    {/if}
    {#if showSettings}
      <button
        class="sidebar-item"
        class:active={route === 'settings' || route === 'user-profile'}
        onclick={() => nav('settings')}
      >
        <span class="sidebar-icon-wrap">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83 0 2 2 0 010-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"/></svg>
          {#if hasUpdate}<span class="update-dot"></span>{/if}
        </span>
        Settings
      </button>
    {/if}
    {#if featureEnabled('ranger')}
      <button
        class="sidebar-item"
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

  /* Accordion group labels */
  .sidebar-group-label {
    display: flex;
    align-items: center;
    width: 100%;
    padding: 4px 0;
    background: none;
    border: none;
    font-size: 10px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: var(--sidebar-text-muted, rgba(255,255,255,0.35));
    cursor: pointer;
    margin-bottom: 2px;
    transition: color 0.1s;
  }

  .sidebar-group-label:hover {
    color: var(--sidebar-text-secondary, rgba(255,255,255,0.55));
  }

  .sidebar-group-chevron {
    width: 12px;
    height: 12px;
    margin-left: auto;
    transition: transform 0.15s;
    opacity: 0;
    flex-shrink: 0;
  }

  .sidebar-group-label:hover .sidebar-group-chevron {
    opacity: 0.6;
  }

  .sidebar-group-chevron.rotated {
    transform: rotate(180deg);
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

  .sidebar-icon-wrap {
    position: relative;
    display: inline-flex;
    flex-shrink: 0;
  }
  .sidebar-icon-wrap svg {
    width: 22px;
    height: 22px;
  }
  .update-dot {
    position: absolute;
    top: -2px;
    right: -2px;
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #22c55e;
    border: 2px solid var(--sidebar-bg);
    box-sizing: content-box;
  }

  .sidebar-sub-divider {
    height: 1px;
    background: var(--sidebar-border);
    margin: 4px 12px 4px 42px;
    opacity: 0.4;
  }
</style>
