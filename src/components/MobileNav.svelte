<script>
  import { auth, collections as collectionsApi } from '$lib/api.js';
  import {
    currentRoute,
    navigate,
    user,
    searchOpen,
    collectionsList,
    canManageSettings,
    canAccessCodeEditor,
    canManageChannels,
    canBuildForms,
    collectionGrants,
    updateAvailable,
  } from '$lib/stores.js';

  let route = $derived($currentRoute);
  let colls = $derived($collectionsList);
  let showSettings = $derived($canManageSettings);
  let showCode = $derived($canAccessCodeEditor);
  let showChannels = $derived($canManageChannels);
  let showFormBuilder = $derived($canBuildForms);
  let grants = $derived($collectionGrants);
  let filteredColls = $derived(
    grants === null ? colls : colls.filter(c => grants.includes(c.id))
  );

  let hasUpdate = $derived($updateAvailable);
  let drawerOpen = $state(false);

  function nav(r, params = {}) {
    navigate(r, params);
    drawerOpen = false;
  }

  function toggleDrawer() {
    drawerOpen = !drawerOpen;
  }

  function closeDrawer() {
    drawerOpen = false;
  }

  async function handleLogout() {
    await auth.logout();
    user.set(null);
    drawerOpen = false;
  }

  // Active state helpers
  let isDashboard = $derived(route === 'dashboard');
  let isPages = $derived(route === 'pages' || route === 'page-editor');
  let isAnalytics = $derived(route === 'analytics' || route.startsWith('analytics-'));
  let isForms = $derived(route === 'forms');
  let isMore = $derived(
    !isDashboard && !isPages && !isAnalytics && !isForms
  );
</script>

<!-- Bottom Tab Bar -->
<nav class="mobile-tab-bar">
  <button class="mobile-tab" class:active={isDashboard} onclick={() => nav('dashboard')}>
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="12" r="10"/><polygon points="16.24 7.76 14.12 14.12 7.76 16.24 9.88 9.88 16.24 7.76"/></svg>
    <span>Dashboard</span>
  </button>
  <button class="mobile-tab" class:active={isPages} onclick={() => nav('pages')}>
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
    <span>Pages</span>
  </button>
  <button class="mobile-tab" class:active={isAnalytics} onclick={() => nav('analytics')}>
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
    <span>Analytics</span>
  </button>
  <button class="mobile-tab" class:active={isForms} onclick={() => nav('forms')}>
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
    <span>Forms</span>
  </button>
  <button class="mobile-tab" class:active={drawerOpen || isMore} onclick={toggleDrawer}>
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="5" cy="5" r="1.5"/><circle cx="12" cy="5" r="1.5"/><circle cx="19" cy="5" r="1.5"/><circle cx="5" cy="12" r="1.5"/><circle cx="12" cy="12" r="1.5"/><circle cx="19" cy="12" r="1.5"/><circle cx="5" cy="19" r="1.5"/><circle cx="12" cy="19" r="1.5"/><circle cx="19" cy="19" r="1.5"/></svg>
    <span>More</span>
  </button>
</nav>

<!-- More Drawer -->
{#if drawerOpen}
  <!-- svelte-ignore a11y_no_static_element_interactions -->
  <div class="mobile-drawer-backdrop" onclick={closeDrawer} onkeydown={() => {}}></div>
  <div class="mobile-drawer">
    <div class="mobile-drawer-handle"></div>

    <!-- Search -->
    <button class="mobile-drawer-item" onclick={() => { searchOpen.set(true); closeDrawer(); }}>
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
      Search
    </button>

    <div class="mobile-drawer-divider"></div>

    <!-- Content -->
    <div class="mobile-drawer-label">Content</div>
    <button class="mobile-drawer-item" class:active={route === 'media'} onclick={() => nav('media')}>
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
      Media
    </button>
    <button class="mobile-drawer-item" class:active={route === 'globals'} onclick={() => nav('globals')}>
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 014 10 15.3 15.3 0 01-4 10 15.3 15.3 0 01-4-10 15.3 15.3 0 014-10z"/></svg>
      Globals
    </button>
    <button class="mobile-drawer-item" class:active={route === 'navigation'} onclick={() => nav('navigation')}>
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
      Navigation
    </button>

    <!-- Collections -->
    {#if filteredColls.length > 0}
      <div class="mobile-drawer-divider"></div>
      <div class="mobile-drawer-label">Collections</div>
      {#each filteredColls as coll}
        <button class="mobile-drawer-item" onclick={() => nav('collection-items', { collectionSlug: coll.slug })}>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M22 19a2 2 0 01-2 2H4a2 2 0 01-2-2V5a2 2 0 012-2h5l2 3h9a2 2 0 012 2z"/></svg>
          {coll.name}
        </button>
      {/each}
    {/if}

    <!-- Build -->
    <div class="mobile-drawer-divider"></div>
    <div class="mobile-drawer-label">Build</div>
    <button class="mobile-drawer-item" class:active={route === 'collections'} onclick={() => nav('collections')}>
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
      Collections
    </button>
    {#if showChannels}
      <button class="mobile-drawer-item" class:active={route === 'channels' || route === 'channel-builder'} onclick={() => nav('channels')}>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M4.9 19.1C1 15.2 1 8.8 4.9 4.9"/><path d="M7.8 16.2c-2.3-2.3-2.3-6.1 0-8.4"/><circle cx="12" cy="12" r="2"/><path d="M16.2 7.8c2.3 2.3 2.3 6.1 0 8.4"/><path d="M19.1 4.9C23 8.8 23 15.2 19.1 19.1"/></svg>
        Channels
      </button>
    {/if}
    <button class="mobile-drawer-item" class:active={route === 'folder-manager'} onclick={() => nav('folder-manager')}>
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M20.59 13.41l-7.17 7.17a2 2 0 01-2.83 0L2 12V2h10l8.59 8.59a2 2 0 010 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>
      Folders
    </button>

    <!-- Design -->
    <div class="mobile-drawer-divider"></div>
    <div class="mobile-drawer-label">Design</div>
    {#if showSettings}
      <button class="mobile-drawer-item" class:active={route === 'themes' || route === 'theme-customizer'} onclick={() => nav('themes')}>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>
        Themes
      </button>
      <button class="mobile-drawer-item" class:active={route === 'brand'} onclick={() => nav('brand')}>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="13.5" cy="6.5" r="2.5"/><circle cx="17.5" cy="15.5" r="2.5"/><circle cx="8.5" cy="15.5" r="2.5"/><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.93 0 1.5-.73 1.5-1.5 0-.39-.15-.74-.39-1.04-.24-.3-.39-.65-.39-1.04 0-.83.67-1.5 1.5-1.5H16c3.31 0 6-2.69 6-6 0-5.52-4.48-9.92-10-9.92z"/></svg>
        Brand
      </button>
    {/if}
    {#if showCode}
      <button class="mobile-drawer-item" class:active={route === 'code-editor' || route === 'template-reference'} onclick={() => nav('code-editor')}>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>
        Code Editor
      </button>
    {/if}

    <!-- Account -->
    <div class="mobile-drawer-divider"></div>
    <div class="mobile-drawer-label">Account</div>
    {#if showSettings}
      <button class="mobile-drawer-item" class:active={route === 'backups'} onclick={() => nav('backups')}>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M21 8v13H3V8"/><path d="M1 3h22v5H1z"/><path d="M10 12h4"/></svg>
        Backups
      </button>
      <button class="mobile-drawer-item" class:active={route === 'settings'} onclick={() => nav('settings')}>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83 0 2 2 0 010-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"/></svg>
        Settings
        {#if hasUpdate}<span class="mobile-drawer-badge"></span>{/if}
      </button>
    {/if}
    <button class="mobile-drawer-item" onclick={() => nav('user-profile')}>
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
      Profile
    </button>
    <button class="mobile-drawer-item mobile-drawer-logout" onclick={handleLogout}>
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
      Log out
    </button>
  </div>
{/if}

<style>
  /* ── Bottom Tab Bar ── */
  .mobile-tab-bar {
    display: none;
  }

  @media (max-width: 768px) {
    .mobile-tab-bar {
      display: flex;
      position: fixed;
      bottom: 0;
      left: 0;
      right: 0;
      z-index: 60;
      background: var(--sidebar-bg);
      border-top: 1px solid var(--sidebar-border);
      padding-bottom: env(safe-area-inset-bottom, 0px);
      height: calc(64px + env(safe-area-inset-bottom, 0px));
      align-items: flex-start;
      overflow: hidden;
    }
  }

  /* Topography texture — same as desktop sidebar */
  .mobile-tab-bar::before {
    content: '';
    position: absolute;
    inset: 0;
    background-image: url('../assets/topography-bg.jpg');
    background-size: cover;
    background-position: center;
    filter: invert(1) brightness(1.5);
    opacity: 0.08;
    pointer-events: none;
    z-index: 0;
  }

  .mobile-tab {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 4px;
    padding: 10px 0 6px;
    background: none;
    border: none;
    color: var(--sidebar-text-muted);
    cursor: pointer;
    transition: color 0.1s;
    -webkit-tap-highlight-color: transparent;
    position: relative;
    z-index: 1;
  }

  .mobile-tab svg {
    width: 22px;
    height: 22px;
    stroke-width: 1.5;
    flex-shrink: 0;
    opacity: 0.7;
    transition: opacity 0.1s;
  }

  .mobile-tab span {
    font-size: 10px;
    font-weight: 500;
    letter-spacing: 0.01em;
  }

  .mobile-tab.active {
    color: var(--sidebar-text);
  }

  .mobile-tab.active svg {
    color: var(--accent);
    opacity: 1;
  }

  /* ── Drawer Backdrop ── */
  .mobile-drawer-backdrop {
    display: none;
  }

  @media (max-width: 768px) {
    .mobile-drawer-backdrop {
      display: block;
      position: fixed;
      inset: 0;
      z-index: 70;
      background: rgba(0, 0, 0, 0.45);
      backdrop-filter: blur(2px);
      -webkit-backdrop-filter: blur(2px);
      animation: fade-in 0.15s ease;
    }
  }

  /* ── More Drawer ── */
  .mobile-drawer {
    display: none;
  }

  @media (max-width: 768px) {
    .mobile-drawer {
      display: block;
      position: fixed;
      bottom: 0;
      left: 0;
      right: 0;
      z-index: 71;
      background: var(--sidebar-bg);
      border-radius: var(--radius-lg) var(--radius-lg) 0 0;
      max-height: 75vh;
      overflow-y: auto;
      overflow-x: hidden;
      padding: 6px var(--space-sm) calc(var(--space-xl) + env(safe-area-inset-bottom, 0px));
      animation: drawer-up 0.25s cubic-bezier(0.4, 0, 0.2, 1);
      -webkit-overflow-scrolling: touch;
    }
  }

  /* Topography texture — same as desktop sidebar */
  .mobile-drawer::before {
    content: '';
    position: fixed;
    bottom: 0;
    left: 0;
    right: 0;
    height: 75vh;
    background-image: url('../assets/topography-bg.jpg');
    background-size: cover;
    background-position: center;
    filter: invert(1) brightness(1.5);
    opacity: 0.08;
    pointer-events: none;
    z-index: 0;
    border-radius: var(--radius-lg) var(--radius-lg) 0 0;
  }

  .mobile-drawer > * {
    position: relative;
    z-index: 1;
  }

  @keyframes drawer-up {
    from { transform: translateY(100%); }
    to   { transform: translateY(0); }
  }

  @keyframes fade-in {
    from { opacity: 0; }
    to   { opacity: 1; }
  }

  .mobile-drawer-handle {
    width: 36px;
    height: 4px;
    background: var(--sidebar-text-muted);
    border-radius: 2px;
    margin: 4px auto 8px;
    opacity: 0.35;
  }

  /* Match .sidebar-label exactly */
  .mobile-drawer-label {
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: var(--sidebar-text-muted);
    padding: var(--space-sm) var(--space-md);
    margin-bottom: var(--space-xs);
  }

  .mobile-drawer-divider {
    height: 1px;
    background: var(--sidebar-border);
    margin: var(--space-sm) var(--space-md);
  }

  /* Match .sidebar-item exactly — same gap, font-size, padding, radius */
  .mobile-drawer-item {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
    width: 100%;
    padding: var(--space-sm) var(--space-md);
    min-height: 44px;
    background: none;
    border: none;
    border-radius: var(--radius-md);
    color: var(--sidebar-text-secondary);
    font-size: 15px;
    font-weight: 500;
    cursor: pointer;
    text-align: left;
    transition: all var(--transition-fast);
    -webkit-tap-highlight-color: transparent;
  }

  .mobile-drawer-item svg {
    width: 22px;
    height: 22px;
    stroke-width: 1.5;
    opacity: 0.7;
    flex-shrink: 0;
    transition: opacity var(--transition-fast);
  }

  .mobile-drawer-item:hover,
  .mobile-drawer-item:active {
    background: var(--sidebar-bg-hover);
    color: var(--sidebar-text);
  }

  .mobile-drawer-item:hover svg,
  .mobile-drawer-item:active svg {
    opacity: 1;
  }

  .mobile-drawer-item.active {
    background: var(--sidebar-bg-active);
    color: var(--sidebar-text);
    font-weight: 500;
  }

  .mobile-drawer-item.active svg {
    opacity: 1;
  }

  .mobile-drawer-badge {
    display: inline-block;
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: var(--accent);
    margin-left: 6px;
    flex-shrink: 0;
  }

  .mobile-drawer-logout {
    color: var(--danger);
  }

  .mobile-drawer-logout:hover,
  .mobile-drawer-logout:active {
    background: var(--danger-soft);
    color: var(--danger);
  }
</style>
