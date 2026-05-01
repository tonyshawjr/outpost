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
    darkMode,
  } from '$lib/stores.js';
  import { onMount } from 'svelte';
  import outpostLogo from '../assets/outpost.svg';

  let route = $derived($currentRoute);
  let colls = $derived($collectionsList);
  let activeCollSlug = $derived($currentCollectionSlug);
  let activeStatusFilter = $derived($currentStatusFilter);
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
  let currentUser = $derived($user);
  let isDark = $derived($darkMode);

  function featureEnabled(key) {
    if (!ff) return true;
    return ff[key] !== false;
  }

  let filteredColls = $derived(
    grants === null ? colls : colls.filter(c => grants.includes(c.id))
  );

  // Posts/Pages sub-nav state
  let postsOpen = $state(false);
  let pagesOpen = $state(false);

  function getCollStats(slug) {
    if (!data?.collections) return null;
    return data.collections.find(c => c.slug === slug);
  }

  // Find pages/posts collections
  let pagesColl = $derived(filteredColls.find(c => c.slug === 'pages'));
  let postsColl = $derived(filteredColls.find(c => c.slug === 'posts' || c.slug === 'blog' || c.slug === 'news'));
  let otherColls = $derived(
    filteredColls.filter(c => c.slug !== 'pages' && c.slug !== 'posts' && c.slug !== 'blog' && c.slug !== 'news')
  );

  // User initials
  let displayName = $derived(currentUser?.display_name || currentUser?.username || 'User');
  let userInitials = $derived(((currentUser?.display_name || currentUser?.username || currentUser?.email || '?')[0] || '?').toUpperCase());
  let userAvatarRaw = $derived(currentUser?.avatar_url || currentUser?.avatar || '');
  let userAvatar = $derived(userAvatarRaw ? (userAvatarRaw.startsWith('http') || userAvatarRaw.startsWith('/') ? userAvatarRaw : '/' + userAvatarRaw) : '');

  let userMenuOpen = $state(false);

  function nav(r, params = {}) {
    navigate(r, params);
    if (window.innerWidth < 768) sidebarOpen.set(false);
  }

  function isSubActive(collSlug, status) {
    return route === 'collection-items' && activeCollSlug === collSlug && activeStatusFilter === status;
  }

  function toggleUserMenu() { userMenuOpen = !userMenuOpen; }
  function toggleDarkMode() { darkMode.update(v => !v); }

  async function handleSignOut() {
    try { await auth.logout(); } catch {}
    window.location.reload();
  }

  // Close user menu on outside click
  $effect(() => {
    if (!userMenuOpen) return;
    function handler(e) {
      if (!e.target.closest('.sb-user-menu-wrap')) userMenuOpen = false;
    }
    setTimeout(() => document.addEventListener('click', handler), 0);
    return () => document.removeEventListener('click', handler);
  });

  function togglePosts() { postsOpen = !postsOpen; }
  function togglePages() { pagesOpen = !pagesOpen; }

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
</script>

<aside class="sidebar">
  <!-- Header: Outpost logo -->
  <div class="sb-head">
    <div class="sb-logo">
      <img src={outpostLogo} alt="Outpost" />
      <span><span style="font-weight:800;">Out</span><span style="font-weight:400;color:var(--sidebar-text-secondary);">post</span></span>
    </div>
  </div>

  <!-- Search trigger -->
  <button class="sb-search-trigger" onclick={() => searchOpen.set(true)}>
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="13" height="13" style="opacity:.7;flex-shrink:0">
      <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
    </svg>
    <span class="sb-search-hint">Search...</span>
    <span class="sb-search-kbd">&#8984;K</span>
  </button>

  <nav class="sb-nav">
    <!-- Top group: Dashboard + Inbox -->
    <div class="sb-group">
      <button class="sb-item" class:active={route === 'dashboard'} onclick={() => nav('dashboard')}>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M3 12L12 3l9 9"/><path d="M5 10v10h14V10"/></svg>
        <span>Dashboard</span>
      </button>
      {#if featureEnabled('forms')}
        <button class="sb-item" class:active={route === 'forms' || route === 'form-submissions'} onclick={() => nav('form-submissions')}>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
          <span>Inbox</span>
        </button>
      {/if}
    </div>

    <!-- Content -->
    {#if (filteredColls.length > 0 && featureEnabled('collections')) || featureEnabled('media')}
      <div class="sb-group">
        <div class="sb-label">Content</div>
        {#if pagesColl && featureEnabled('collections')}
          <button class="sb-item" class:active={route === 'collection-items' && activeCollSlug === pagesColl.slug && activeStatusFilter === 'all'} onclick={() => nav('collection-items', { collectionSlug: pagesColl.slug, statusFilter: 'all' })}>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            <span>Pages</span>
          </button>
        {/if}
        {#if postsColl && featureEnabled('collections')}
          <button class="sb-item sb-toggle" onclick={togglePosts}>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M12 19l7-7 3 3-7 7-3-3z"/><path d="M18 13l-1.5-7.5L2 2l3.5 14.5L13 18l5-5z"/><path d="M2 2l7.586 7.586"/><circle cx="11" cy="11" r="2"/></svg>
            <span>Posts</span>
            <span class="caret" class:open={postsOpen}>
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="12" height="12"><polyline points="9 18 15 12 9 6"/></svg>
            </span>
          </button>
          <div class="sb-sub" class:open={postsOpen}>
            <button class="sb-item" class:active={isSubActive(postsColl.slug, 'draft')} onclick={() => nav('collection-items', { collectionSlug: postsColl.slug, statusFilter: 'draft' })}><span>Drafts</span></button>
            <button class="sb-item" class:active={isSubActive(postsColl.slug, 'scheduled')} onclick={() => nav('collection-items', { collectionSlug: postsColl.slug, statusFilter: 'scheduled' })}><span>Scheduled</span></button>
            <button class="sb-item" class:active={isSubActive(postsColl.slug, 'published')} onclick={() => nav('collection-items', { collectionSlug: postsColl.slug, statusFilter: 'published' })}><span>Published</span></button>
          </div>
        {/if}
        {#each otherColls as coll}
          <button class="sb-item" class:active={route === 'collection-items' && activeCollSlug === coll.slug} onclick={() => nav('collection-items', { collectionSlug: coll.slug, statusFilter: 'all' })}>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
            <span>{coll.name}</span>
          </button>
        {/each}
        {#if featureEnabled('media')}
          <button class="sb-item" class:active={route === 'media'} onclick={() => nav('media')}>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
            <span>Media</span>
          </button>
        {/if}
      </div>
    {/if}

    <!-- Site -->
    <div class="sb-group">
      <div class="sb-label">Site</div>
      <button class="sb-item" class:active={route === 'globals'} onclick={() => nav('globals')}>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 014 10 15.3 15.3 0 01-4 10 15.3 15.3 0 01-4-10 15.3 15.3 0 014-10z"/></svg>
        <span>Globals</span>
      </button>
      {#if featureEnabled('navigation')}
        <button class="sb-item" class:active={route === 'navigation'} onclick={() => nav('navigation')}>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
          <span>Navigation</span>
        </button>
      {/if}
      {#if showSettings}
        <button class="sb-item" class:active={route === 'brand'} onclick={() => nav('brand')}>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>
          <span>Brand</span>
        </button>
        <button class="sb-item" class:active={route === 'design'} onclick={() => nav('design')}>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="13.5" cy="6.5" r=".5"/><circle cx="17.5" cy="10.5" r=".5"/><circle cx="8.5" cy="7.5" r=".5"/><circle cx="6.5" cy="12.5" r=".5"/><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.926 0 1.648-.746 1.648-1.688 0-.437-.18-.835-.437-1.125-.29-.289-.438-.652-.438-1.125a1.64 1.64 0 0 1 1.668-1.668h1.996c3.051 0 5.555-2.503 5.555-5.554C21.965 6.012 17.461 2 12 2z"/></svg>
          <span>Design</span>
        </button>
      {/if}
    </div>

    <!-- Members -->
    {#if (showMembers && featureEnabled('members')) || featureEnabled('lodge')}
      <div class="sb-group">
        <div class="sb-label">Members</div>
        {#if showMembers && featureEnabled('members')}
          <button class="sb-item" onclick={() => nav('settings', { section: 'members' })}>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg>
            <span>Members</span>
          </button>
        {/if}
        {#if featureEnabled('lodge')}
          <button class="sb-item" class:active={route === 'lodge'} onclick={() => nav('lodge')}>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
            <span>Lodge</span>
          </button>
        {/if}
      </div>
    {/if}

    <!-- Build -->
    <div class="sb-group">
      <div class="sb-label">Build</div>
      <button class="sb-item" class:active={route === 'page-builder'} onclick={() => nav('page-builder')}>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/></svg>
        <span>Page Builder</span>
      </button>
      {#if showCode && featureEnabled('code_editor')}
        <button class="sb-item" class:active={route === 'code-editor' || route === 'template-reference'} onclick={() => nav('code-editor')}>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>
          <span>Code Editor</span>
        </button>
      {/if}
      {#if showFormBuilder && featureEnabled('forms')}
        <button class="sb-item" class:active={route === 'forms-list' || route === 'form-builder'} onclick={() => nav('forms-list')}>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M9 11H3v10h6V11z"/><path d="M21 3H3v6h18V3z"/><path d="M21 11h-6v10h6V11z"/></svg>
          <span>Forms</span>
        </button>
      {/if}
      {#if showChannels && featureEnabled('channels')}
        <button class="sb-item" class:active={route === 'channels' || route === 'channel-builder'} onclick={() => nav('channels')}>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M4.9 19.1C1 15.2 1 8.8 4.9 4.9"/><path d="M7.8 16.2c-2.3-2.3-2.3-6.1 0-8.4"/><circle cx="12" cy="12" r="2"/><path d="M16.2 7.8c2.3 2.3 2.3 6.1 0 8.4"/><path d="M19.1 4.9C23 8.8 23 15.2 19.1 19.1"/></svg>
          <span>Channels</span>
        </button>
      {/if}
      {#if featureEnabled('collections')}
        <button class="sb-item" class:active={route === 'collections'} onclick={() => nav('collections')}>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
          <span>Collections</span>
        </button>
      {/if}
      <button class="sb-item" class:active={route === 'folder-manager'} onclick={() => nav('folder-manager')}>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M22 19a2 2 0 01-2 2H4a2 2 0 01-2-2V5a2 2 0 012-2h5l2 3h9a2 2 0 012 2z"/></svg>
        <span>Folders</span>
      </button>
    </div>

    <!-- Tools -->
    {#if (showCode && featureEnabled('analytics')) || showSettings}
      <div class="sb-group">
        <div class="sb-label">Tools</div>
        {#if showCode && featureEnabled('analytics')}
          <button class="sb-item" class:active={route === 'analytics' || route.startsWith('analytics-')} onclick={() => nav('analytics')}>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
            <span>Analytics</span>
          </button>
        {/if}
        {#if showSettings}
          <button class="sb-item" class:active={route === 'redirects'} onclick={() => nav('redirects')}>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><polyline points="9 14 4 9 9 4"/><path d="M20 20v-7a4 4 0 00-4-4H4"/></svg>
            <span>Redirects</span>
          </button>
        {/if}
        {#if showSettings && featureEnabled('shield')}
          <button class="sb-item" onclick={() => nav('settings', { section: 'shield' })}>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            <span>Shield</span>
          </button>
        {/if}
        {#if showSettings && featureEnabled('boost')}
          <button class="sb-item" onclick={() => nav('settings', { section: 'boost' })}>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
            <span>Boost</span>
          </button>
        {/if}
        {#if showSettings && featureEnabled('review_links')}
          <button class="sb-item" class:active={route === 'review-tokens'} onclick={() => nav('review-tokens')}>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M10 13a5 5 0 007.54.54l3-3a5 5 0 00-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 00-7.54-.54l-3 3a5 5 0 007.07 7.07l1.71-1.71"/></svg>
            <span>Review Links</span>
          </button>
        {/if}
        {#if showSettings && featureEnabled('releases')}
          <button class="sb-item" class:active={route === 'releases'} onclick={() => nav('releases')}>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
            <span>Releases</span>
          </button>
        {/if}
        {#if showSettings && featureEnabled('workflows')}
          <button class="sb-item" class:active={route === 'workflows'} onclick={() => nav('workflows')}>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
            <span>Workflows</span>
          </button>
        {/if}
      </div>
    {/if}

    <!-- Bottom group: Settings + Help + Ranger pinned to bottom -->
    <div class="sb-group" style="margin-top:auto">
      {#if showSettings}
        <button class="sb-item" class:active={route === 'settings'} onclick={() => nav('settings', { section: 'general' })}>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83 0 2 2 0 010-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"/></svg>
          <span>Settings</span>
          {#if hasUpdate}<span style="margin-left:auto;width:6px;height:6px;border-radius:50%;background:var(--green)"></span>{/if}
        </button>
      {/if}
      {#if featureEnabled('ranger')}
        <button class="sb-item" class:active={$rangerOpen} onclick={() => rangerOpen.update(v => !v)} title="Ranger AI Assistant">
          <svg viewBox="0 0 24 24" fill="currentColor" width="18" height="18">
            <path d="M10 2L11.5 7.5L17 9L11.5 10.5L10 16L8.5 10.5L3 9L8.5 7.5L10 2Z"/>
            <path d="M18 12L19 15L22 16L19 17L18 20L17 17L14 16L17 15L18 12Z" opacity="0.6"/>
          </svg>
          <span>Ranger</span>
        </button>
      {/if}
    </div>
  </nav>

  <!-- Bottom user card -->
  <div class="sb-foot-wrap sb-user-menu-wrap">
    <button class="sb-user-card" onclick={toggleUserMenu}>
      {#if userAvatar}
        <img class="sb-av-lg" src={userAvatar} alt="" />
      {:else}
        <div class="sb-av-lg sb-av-initials">{userInitials}</div>
      {/if}
      <div class="sb-user-info">
        <span class="sb-user-name">{displayName}</span>
        <span class="sb-user-role">{currentUser?.role?.replace('_', ' ') || 'editor'}</span>
      </div>
      <span class="sb-user-chevron" style="transform: rotate({userMenuOpen ? '270deg' : '90deg'}); transition: transform .2s;">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><polyline points="9 18 15 12 9 6"/></svg>
      </span>
    </button>

    {#if userMenuOpen}
      <div class="sb-user-dropdown">
        <button class="sb-dropdown-item" onclick={() => { userMenuOpen = false; nav('user-profile', { userId: currentUser?.id }); }}>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" width="15" height="15"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
          <span>Your Profile</span>
        </button>
        <button class="sb-dropdown-item" onclick={() => { userMenuOpen = false; searchOpen.set(true); }}>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" width="15" height="15"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
          <span>Search</span>
          <span class="sb-dropdown-kbd">&#8984;K</span>
        </button>
        {#if showSettings && featureEnabled('backups')}
          <button class="sb-dropdown-item" onclick={() => { userMenuOpen = false; nav('backups'); }}>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" width="15" height="15"><path d="M21 8v13H3V8"/><path d="M1 3h22v5H1z"/><path d="M10 12h4"/></svg>
            <span>Backups</span>
          </button>
        {/if}
        <div class="sb-dropdown-divider"></div>
        <div class="sb-dropdown-item sb-dropdown-theme">
          <span class="sb-dropdown-theme-label">{isDark ? 'Dark' : 'Light'} mode</span>
          <button class="sb-theme-toggle" class:dark={isDark} onclick={(e) => { e.stopPropagation(); toggleDarkMode(); }}>
            <span class="sb-theme-track">
              <span class="sb-theme-icon" style="color: {!isDark ? '#fff' : 'var(--dim)'}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="12" height="12"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
              </span>
              <span class="sb-theme-icon" style="color: {isDark ? '#fff' : 'var(--dim)'}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="12" height="12"><path d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z"/></svg>
              </span>
              <span class="sb-theme-knob"></span>
            </span>
          </button>
        </div>
        <div class="sb-dropdown-divider"></div>
        <button class="sb-dropdown-item sb-dropdown-danger" onclick={handleSignOut}>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" width="15" height="15"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
          <span>Sign out</span>
        </button>
      </div>
    {/if}
  </div>
</aside>
