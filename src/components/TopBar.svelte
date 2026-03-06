<script>
  import { currentRoute, darkMode, sidebarOpen, user, navigate, addToast } from '$lib/stores.js';
  import { cache as cacheApi } from '$lib/api.js';

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
  let isDark = $derived($darkMode);
  let currentUser = $derived($user);

  function toggleSidebar() {
    sidebarOpen.update((v) => !v);
  }

  function toggleDarkMode() {
    darkMode.update((v) => !v);
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
  };

  const roleLabels = {
    admin: 'Admin',
    developer: 'Developer',
    editor: 'Editor',
  };
  let roleBadge = $derived(roleLabels[currentUser?.role] || '');

  let title = $derived(routeTitles[route] || 'Dashboard');
  let displayName = $derived(currentUser?.display_name || currentUser?.username || '');
  let avatarUrl = $derived(currentUser?.avatar ? (currentUser.avatar.startsWith('/') ? currentUser.avatar : '/' + currentUser.avatar) : '');
  let initial = $derived((displayName || '?')[0].toUpperCase());

  const avatarColors = ['#4A8B72', '#C4785C', '#7D9B8A', '#B85C4A', '#5B8C5A', '#C49A3D', '#6B8FA3', '#9B7EB8'];
  function getColor(name) {
    let hash = 0;
    for (let i = 0; i < (name || '').length; i++) hash = name.charCodeAt(i) + ((hash << 5) - hash);
    return avatarColors[Math.abs(hash) % avatarColors.length];
  }

  function goToProfile() {
    if (currentUser?.id) {
      navigate('user-profile', { userId: currentUser.id });
    }
  }
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
    <button class="btn btn-ghost btn-sm" onclick={toggleDarkMode} aria-label="Toggle dark mode">
      {#if isDark}
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
      {:else}
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z"/></svg>
      {/if}
    </button>
    {#if currentUser}
      <button class="topbar-user" onclick={goToProfile} title="Edit profile">
        {#if avatarUrl}
          <img src={avatarUrl} alt={displayName} class="topbar-avatar-img" />
        {:else}
          <span class="topbar-avatar-fallback" style="background-color: {getColor(currentUser.username)};">
            {initial}
          </span>
        {/if}
        <span class="topbar-username">{displayName}</span>
        {#if roleBadge}
          <span class="topbar-role-badge">{roleBadge}</span>
        {/if}
      </button>
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

  .topbar-user {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
    background: none;
    border: none;
    padding: 4px 8px 4px 4px;
    border-radius: var(--radius-full, 999px);
    cursor: pointer;
    transition: background var(--transition-fast);
    font-family: var(--font-sans);
  }

  .topbar-user:hover {
    background: var(--bg-hover);
  }

  .topbar-avatar-img {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    object-fit: cover;
  }

  .topbar-avatar-fallback {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 12px;
    font-weight: 600;
    flex-shrink: 0;
  }

  .topbar-username {
    font-size: var(--font-size-sm);
    color: var(--text-secondary);
    font-weight: 500;
  }

  .topbar-role-badge {
    font-size: 10px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--text-tertiary);
    padding: 1px 6px;
    border: 1px solid var(--border);
    border-radius: var(--radius-full, 999px);
  }
</style>
