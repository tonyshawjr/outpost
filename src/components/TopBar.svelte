<script>
  import { currentRoute, sidebarOpen, rangerOpen, user, addToast } from '$lib/stores.js';
  import { cache as cacheApi } from '$lib/api.js';
  import AvatarMenu from '$components/AvatarMenu.svelte';

  let clearingCache = $state(false);

  async function clearCache() {
    if (clearingCache) return;
    clearingCache = true;
    try {
      await cacheApi.clear();
      addToast('Cache cleared', 'success');
    } catch (e) {
      addToast('Could not clear cache', 'error');
    } finally {
      clearingCache = false;
    }
  }

  let route = $derived($currentRoute);
  let currentUser = $derived($user);

  function toggleSidebar() {
    sidebarOpen.update((v) => !v);
  }

  const routeTitles = {
    dashboard: 'Dashboard',
    analytics: 'Analytics',
    pages: 'Pages',
    'page-editor': 'Edit Page',
    collections: 'Collections',
    'collection-items': 'Collection',
    'collection-editor': 'Edit Item',
    media: 'Media Library',
    globals: 'Global Settings',
    users: 'Users',
    'user-profile': 'User Profile',
    'folder-manager': 'Folders',
    'folder-labels': 'Manage Labels',
    'folder-edit': 'Edit Folder',
    'folder-label-edit': 'Edit Label',
    'code-editor': 'Code Editor',
    'template-reference': 'Template Reference',
    themes: 'Themes',
    members: 'Members',
    settings: 'Settings',
    navigation: 'Navigation',
    import: 'Import',
    redirects: 'Redirects',
  };

  let title = $derived(routeTitles[route] || 'Dashboard');
</script>

<header class="topbar">
  <div style="display: flex; align-items: center; gap: var(--space-md);">
    <button class="btn btn-ghost btn-sm mobile-sidebar-toggle" onclick={toggleSidebar} aria-label="Toggle sidebar">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
    </button>
    <h1 class="topbar-title">{title}</h1>
  </div>

  <div class="topbar-actions">
    <a href="/" target="_blank" rel="noopener" class="btn btn-ghost btn-sm topbar-view-site" title="View site">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
      View site
    </a>
    <button
      class="btn btn-ghost btn-sm"
      onclick={clearCache}
      disabled={clearingCache}
      title="Clear page cache"
      aria-label="Clear cache"
    >
      {#if clearingCache}
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="spinning"><path d="M21 12a9 9 0 11-6.219-8.56"/></svg>
      {:else}
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 102.13-9.36L1 10"/></svg>
      {/if}
    </button>
    <button class="btn btn-ghost btn-sm" class:ranger-active={$rangerOpen} onclick={() => rangerOpen.update(v => !v)} aria-label="Toggle Ranger AI" title="Ranger AI Assistant">
      <svg viewBox="0 0 24 24" fill="currentColor" width="18" height="18">
        <path d="M10 2L11.5 7.5L17 9L11.5 10.5L10 16L8.5 10.5L3 9L8.5 7.5L10 2Z"/>
        <path d="M18 12L19 15L22 16L19 17L18 20L17 17L14 16L17 15L18 12Z" opacity="0.6"/>
      </svg>
    </button>
    {#if currentUser}
      <AvatarMenu />
    {/if}
  </div>
</header>

<style>
  .topbar-view-site {
    gap: 5px;
    text-decoration: none;
    font-size: var(--font-size-xs);
  }

  .spinning {
    animation: spin 0.8s linear infinite;
  }

  @keyframes spin {
    from { transform: rotate(0deg); }
    to   { transform: rotate(360deg); }
  }

  .mobile-sidebar-toggle {
    display: none;
  }

  @media (max-width: 768px) {
    .mobile-sidebar-toggle {
      display: none;
    }
  }

  .ranger-active {
    color: var(--accent);
  }
</style>
