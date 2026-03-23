<script>
  import { user, darkMode, navigate, canManageSettings, updateAvailable, featureFlags } from '$lib/stores.js';
  import { auth } from '$lib/api.js';

  let currentUser = $derived($user);
  let showSettings = $derived($canManageSettings);
  let hasUpdate = $derived($updateAvailable);
  let isDark = $derived($darkMode);
  let ff = $derived($featureFlags);
  function featureEnabled(key) {
    if (!ff) return true;
    return ff[key] !== false;
  }

  let open = $state(false);
  let menuRef = $state(null);

  let displayName = $derived(currentUser?.display_name || currentUser?.username || '');
  let avatarUrl = $derived(currentUser?.avatar ? (currentUser.avatar.startsWith('/') ? currentUser.avatar : '/' + currentUser.avatar) : '');
  let initial = $derived((displayName || '?')[0].toUpperCase());

  const avatarColors = ['#4A8B72', '#C4785C', '#7D9B8A', '#B85C4A', '#5B8C5A', '#C49A3D', '#6B8FA3', '#9B7EB8'];
  function getColor(name) {
    let hash = 0;
    for (let i = 0; i < (name || '').length; i++) hash = name.charCodeAt(i) + ((hash << 5) - hash);
    return avatarColors[Math.abs(hash) % avatarColors.length];
  }

  function toggle() {
    open = !open;
  }

  function nav(route, params = {}) {
    navigate(route, params);
    open = false;
  }

  async function handleLogout() {
    await auth.logout();
    user.set(null);
  }

  function toggleDarkMode() {
    darkMode.update((v) => !v);
  }

  // Close on outside click
  function handleWindowClick(e) {
    if (menuRef && !menuRef.contains(e.target)) {
      open = false;
    }
  }
</script>

<svelte:window onclick={handleWindowClick} />

<div class="avatar-menu" bind:this={menuRef}>
  <button class="avatar-trigger" onclick={toggle} title="Account menu">
    {#if avatarUrl}
      <img src={avatarUrl} alt={displayName} class="avatar-img" />
    {:else}
      <span class="avatar-fallback" style="background-color: {getColor(currentUser?.username)};">
        {initial}
      </span>
    {/if}
  </button>

  {#if open}
    <div class="avatar-dropdown">
      <div class="dropdown-user">
        <span class="dropdown-name">{displayName}</span>
        {#if currentUser?.role}
          <span class="dropdown-role">{currentUser.role.replace('_', ' ')}</span>
        {/if}
      </div>
      <div class="dropdown-divider"></div>
      <button class="dropdown-item" onclick={() => nav('user-profile', { userId: currentUser?.id })}>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="15" height="15"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        My Profile
      </button>
      {#if showSettings}
        <button class="dropdown-item" onclick={() => nav('settings')}>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="15" height="15"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83 0 2 2 0 010-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"/></svg>
          Settings
          {#if hasUpdate}<span class="update-indicator"></span>{/if}
        </button>
      {/if}
      {#if showSettings && featureEnabled('backups')}
        <button class="dropdown-item" onclick={() => nav('backups')}>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="15" height="15"><path d="M21 8v13H3V8"/><path d="M1 3h22v5H1z"/><path d="M10 12h4"/></svg>
          Backups
        </button>
      {/if}
      <button class="dropdown-item" onclick={() => nav('calendar')}>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="15" height="15"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
        Calendar
      </button>
      <div class="dropdown-divider"></div>
      <button class="dropdown-item" onclick={toggleDarkMode}>
        {#if isDark}
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="15" height="15"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
          Light Mode
        {:else}
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="15" height="15"><path d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z"/></svg>
          Dark Mode
        {/if}
      </button>
      <div class="dropdown-divider"></div>
      <button class="dropdown-item dropdown-item-danger" onclick={handleLogout}>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="15" height="15"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
        Log out
      </button>
    </div>
  {/if}
</div>

<style>
  .avatar-menu {
    position: relative;
  }

  .avatar-trigger {
    background: none;
    border: none;
    padding: 0;
    cursor: pointer;
    border-radius: 50%;
    transition: opacity 0.15s;
  }

  .avatar-trigger:hover {
    opacity: 0.85;
  }

  .avatar-img {
    width: 30px;
    height: 30px;
    border-radius: 50%;
    object-fit: cover;
  }

  .avatar-fallback {
    width: 30px;
    height: 30px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 12px;
    font-weight: 600;
  }

  .avatar-dropdown {
    position: absolute;
    top: calc(100% + 8px);
    right: 0;
    min-width: 200px;
    background: var(--bg-elevated, var(--bg));
    border: 1px solid var(--border);
    border-radius: var(--radius-lg, 8px);
    box-shadow: 0 8px 24px rgba(0,0,0,0.12);
    padding: 4px;
    z-index: 1000;
  }

  .dropdown-user {
    padding: 10px 12px 8px;
  }

  .dropdown-name {
    display: block;
    font-size: 13px;
    font-weight: 600;
    color: var(--text-primary);
  }

  .dropdown-role {
    font-size: 11px;
    color: var(--text-tertiary);
    text-transform: capitalize;
  }

  .dropdown-divider {
    height: 1px;
    background: var(--border);
    margin: 4px 0;
  }

  .dropdown-item {
    display: flex;
    align-items: center;
    gap: 8px;
    width: 100%;
    padding: 7px 12px;
    background: none;
    border: none;
    font-size: 13px;
    color: var(--text-secondary);
    cursor: pointer;
    border-radius: var(--radius-md, 6px);
    text-align: left;
    transition: background 0.1s, color 0.1s;
    font-family: var(--font-sans);
  }

  .dropdown-item:hover {
    background: var(--bg-hover);
    color: var(--text-primary);
  }

  .dropdown-item-danger {
    color: var(--color-error, #ef4444);
  }

  .dropdown-item-danger:hover {
    color: var(--color-error, #ef4444);
    background: rgba(239, 68, 68, 0.08);
  }

  .update-indicator {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: #22c55e;
    flex-shrink: 0;
  }
</style>
