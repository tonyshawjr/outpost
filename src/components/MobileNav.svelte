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
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M9 18a2 2 0 01-2-2V4a2 2 0 012-2h7l4 4v10a2 2 0 01-2 2z"/><path d="M15 2v6h6"/><path d="M3 8v12a2 2 0 002 2h10"/></svg>
          {coll.name}
        </button>
      {/each}
    {/if}

    <!-- Build -->
    <div class="mobile-drawer-divider"></div>
    <div class="mobile-drawer-label">Content</div>
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
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M22 19a2 2 0 01-2 2H4a2 2 0 01-2-2V5a2 2 0 012-2h5l2 3h9a2 2 0 012 2z"/></svg>
      Folders
    </button>

    <!-- Design -->
    <div class="mobile-drawer-divider"></div>
    <div class="mobile-drawer-label">Design & Build</div>
    {#if showSettings}
      <button class="mobile-drawer-item" class:active={route === 'themes' || route === 'theme-customizer'} onclick={() => nav('themes')}>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>
        Themes
      </button>
      <button class="mobile-drawer-item" class:active={route === 'brand'} onclick={() => nav('brand')}>
        <svg viewBox="0 0 324.99 324.99" fill="currentColor"><path d="M307.6,129.885c-11.453-11.447-23.783-16.778-38.805-16.778c-6.189,0-12.056,0.858-17.729,1.688c-5.094,0.745-9.905,1.449-14.453,1.45c-8.27,0-14.197-2.397-19.82-8.017c-10.107-10.101-8.545-20.758-6.569-34.25c2.357-16.096,5.291-36.127-15.101-56.508C183.578,5.932,167.848,0.081,148.372,0.081c-37.296,0-78.367,21.546-99.662,42.829C17.398,74.205,0.1,115.758,0,159.917c-0.1,44.168,17.018,85.656,48.199,116.82c31.077,31.061,72.452,48.168,116.504,48.171c0.005,0,0.007,0,0.013,0c44.315,0,86.02-17.289,117.428-48.681c17.236-17.226,32.142-44.229,38.9-70.471C329.291,173.738,324.517,146.793,307.6,129.885z M309.424,202.764c-6.16,23.915-20.197,49.42-35.763,64.976c-29.145,29.129-67.833,45.17-108.946,45.169c-0.002,0-0.009,0-0.011,0c-40.849-0.003-79.211-15.863-108.023-44.659C27.777,239.36,11.908,200.896,12,159.944c0.092-40.962,16.142-79.512,45.191-108.545c19.071-19.061,57.508-39.317,91.18-39.317c16.18,0,29.056,4.669,38.269,13.877c16.127,16.118,13.981,30.769,11.71,46.28c-2.067,14.116-4.41,30.115,9.96,44.478c7.871,7.866,16.864,11.529,28.304,11.528c5.421-0.001,10.895-0.802,16.189-1.576c5.248-0.768,10.676-1.562,15.992-1.562c7.938,0,18.557,1.508,30.322,13.267C317.724,156.971,313.562,186.699,309.424,202.764z"/><path d="M142.002,43.531c-1.109,0-2.233,0.065-3.342,0.192c-15.859,1.824-27.33,16.199-25.571,32.042c1.613,14.631,13.93,25.665,28.647,25.665c1.105,0,2.226-0.065,3.332-0.191c15.851-1.823,27.326-16.191,25.581-32.031C169.032,54.57,156.716,43.531,142.002,43.531z M143.7,89.317c-0.652,0.075-1.313,0.113-1.963,0.113c-8.59,0-15.778-6.441-16.721-14.985c-1.032-9.296,5.704-17.729,15.016-18.8c0.655-0.075,1.317-0.114,1.971-0.114c8.587,0,15.775,6.446,16.72,14.993C159.747,79.816,153.006,88.247,143.7,89.317z"/><path d="M102.997,113.64c-1.72-7.512-6.261-13.898-12.784-17.984c-4.597-2.881-9.889-4.404-15.304-4.404c-10.051,0-19.254,5.079-24.618,13.587c-4.14,6.566-5.472,14.34-3.75,21.888c1.715,7.52,6.261,13.92,12.799,18.018c4.596,2.88,9.888,4.402,15.303,4.402c10.051,0,19.255-5.078,24.624-13.593C103.401,128.975,104.726,121.193,102.997,113.64z M89.111,129.16c-3.153,5.001-8.563,7.986-14.469,7.986c-3.158,0-6.246-0.889-8.93-2.57c-3.817-2.393-6.471-6.128-7.472-10.518c-1.008-4.417-0.227-8.97,2.2-12.819c3.153-5.001,8.562-7.987,14.468-7.987c3.158,0,6.246,0.89,8.933,2.573c3.806,2.384,6.454,6.11,7.458,10.493C92.312,120.743,91.534,125.306,89.111,129.16z"/><path d="M70.131,173.25c-3.275,0-6.516,0.557-9.63,1.654c-15.055,5.301-23.05,21.849-17.821,36.892c4.032,11.579,14.984,19.358,27.254,19.358c3.276,0,6.517-0.556,9.637-1.652c15.065-5.301,23.053-21.854,17.806-36.896C93.346,181.029,82.397,173.25,70.131,173.25z M75.589,218.182c-1.836,0.646-3.738,0.973-5.655,0.973c-7.168,0-13.566-4.543-15.921-11.302c-3.063-8.814,1.636-18.518,10.476-21.63c1.83-0.645,3.729-0.973,5.643-0.973c7.165,0,13.56,4.542,15.914,11.304C89.12,205.37,84.429,215.072,75.589,218.182z"/><path d="M140.817,229.415c-3.071-1.066-6.266-1.606-9.496-1.606c-12.307,0-23.328,7.804-27.431,19.429c-2.566,7.317-2.131,15.185,1.229,22.151c3.349,6.943,9.204,12.163,16.486,14.696c3.075,1.071,6.274,1.614,9.51,1.614c12.3,0,23.314-7.811,27.409-19.439c2.574-7.31,2.143-15.175-1.216-22.145C153.958,237.165,148.103,231.945,140.817,229.415z M147.206,262.275c-2.407,6.834-8.873,11.425-16.091,11.425c-1.888,0-3.759-0.318-5.563-0.947c-4.253-1.48-7.67-4.524-9.623-8.575c-1.965-4.074-2.219-8.68-0.718-12.957c2.408-6.825,8.883-11.411,16.11-11.411c1.888,0,3.759,0.317,5.561,0.942c4.248,1.475,7.663,4.52,9.616,8.573C148.46,253.399,148.711,257.998,147.206,262.275z"/><path d="M212.332,213.811c-5.466,0-10.81,1.55-15.448,4.479c-13.525,8.521-17.652,26.427-9.193,39.927c5.315,8.445,14.463,13.488,24.469,13.488c5.458,0,10.796-1.545,15.434-4.464c13.541-8.507,17.663-26.419,9.19-39.926C231.486,218.86,222.345,213.811,212.332,213.811z M221.205,257.082c-2.725,1.715-5.853,2.622-9.045,2.622c-5.857,0-11.207-2.946-14.307-7.87c-4.947-7.896-2.513-18.39,5.433-23.395c2.724-1.72,5.852-2.629,9.047-2.629c5.854,0,11.192,2.944,14.283,7.878C231.577,241.597,229.151,252.09,221.205,257.082z"/><path d="M255.384,141.998c-1.06-0.117-2.134-0.176-3.194-0.176c-14.772,0-27.174,11.068-28.846,25.747c-0.876,7.698,1.297,15.266,6.118,21.311c4.812,6.03,11.686,9.821,19.369,10.676c1.053,0.114,2.12,0.173,3.175,0.173c14.754,0,27.164-11.067,28.869-25.748c0.886-7.688-1.277-15.247-6.091-21.288C269.97,146.651,263.082,142.853,255.384,141.998z M268.955,172.602c-1.001,8.624-8.287,15.127-16.948,15.127c-0.621,0-1.251-0.034-1.86-0.101c-4.48-0.498-8.494-2.712-11.303-6.231c-2.819-3.534-4.089-7.963-3.575-12.47c0.98-8.611,8.255-15.104,16.922-15.104c0.623,0,1.256,0.035,1.875,0.104c4.498,0.499,8.523,2.717,11.334,6.244C268.209,163.697,269.472,168.114,268.955,172.602z"/></svg>
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
