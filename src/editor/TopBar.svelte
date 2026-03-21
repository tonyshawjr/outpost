<script>
  import PublishDropdown from './PublishDropdown.svelte';

  let {
    pageName = '',
    activeUsers = [],
    hasChanges = false,
    saving = false,
    userId = 0,
    userName = '',
    userAvatar = null,
    onsave = () => {},
    onpublish = () => {},
    showToast = () => {},
    apiUrl = '/outpost/api.php',
    csrfToken = '',
    pageId = null,
  } = $props();

  let publishOpen = $state(false);

  function getInitials(name) {
    if (!name) return '?';
    return name.split(' ').map(w => w[0]).join('').toUpperCase().slice(0, 2);
  }

  function getAvatarColor(id) {
    const colors = ['#6366f1', '#8b5cf6', '#ec4899', '#f59e0b', '#10b981', '#3b82f6', '#ef4444'];
    return colors[(id || 0) % colors.length];
  }
</script>

<div class="ope-topbar">
  <div class="ope-topbar-left">
    <a href="/outpost/#/dashboard" class="ope-topbar-logo" title="Back to Admin Dashboard">
      <svg viewBox="0 0 80.24 8.18" fill="white" style="height: 11px; width: auto;"><path d="M5.32,0h1.94c1.42.01,2.4.25,2.92.72.52.47.79,1.42.79,2.83v.38c0,1.66-.21,2.78-.62,3.34-.42.56-1.47.85-3.16.85l-1.84.04h-.77s-1.66-.05-1.66-.05c-.93,0-1.64-.21-2.15-.63C.28,7.06.02,6.38.02,5.45l-.02-1.48C0,2.35.21,1.29.62.79,1.04.29,2.03.04,3.59.04l1.73-.04ZM4,6.18l1.31.02h1.25c.69-.01,1.18-.09,1.48-.23.3-.15.45-.52.45-1.1l.02-1.08c0-.37-.02-.68-.07-.95-.04-.26-.13-.45-.27-.58-.14-.12-.34-.21-.61-.25-.27-.04-.55-.06-.86-.06h-2.42c-.55.01-.97.07-1.26.18s-.45.35-.5.72-.07.67-.07.91v.54c0,.74.09,1.24.26,1.49s.6.38,1.28.38Z"/><path d="M23.16,4.66v.47c0,1.25-.33,2.07-.98,2.47-.65.4-1.66.59-3.02.59h-1.99c-1.05-.01-1.86-.07-2.42-.18-.56-.11-1.01-.4-1.36-.85-.34-.46-.52-1.17-.52-2.15V.1h2.44v4.19c0,.8.1,1.32.29,1.56.19.24.68.36,1.45.36h1.09s1.01,0,1.01,0c.66,0,1.09-.1,1.28-.31s.28-.63.28-1.26V.1h2.45v4.56Z"/><path d="M28.09,8.09V2.09h-3.58V.08h9.58v2h-3.56v6h-2.44Z"/><path d="M41.94.08c1.21,0,2.05.2,2.51.59s.7,1.18.7,2.36c0,1.12-.18,1.91-.55,2.37-.36.46-1.13.69-2.29.69h-4.43v1.99h-2.44V.08h6.48ZM42.7,3.12c0-.45-.1-.74-.29-.88-.19-.14-.53-.21-1.01-.21h-3.5v2.12h3.54c.46,0,.79-.07.98-.2.19-.13.28-.41.28-.83Z"/><path d="M52.03,0h1.94c1.42.01,2.4.25,2.92.72.52.47.79,1.42.79,2.83v.38c0,1.66-.21,2.78-.62,3.34-.42.56-1.47.85-3.16.85l-1.84.04h-.77s-1.66-.05-1.66-.05c-.93,0-1.64-.21-2.15-.63-.5-.42-.76-1.1-.76-2.03l-.02-1.48c0-1.62.21-2.69.62-3.19s1.4-.75,2.96-.75l1.73-.04ZM50.71,6.18l1.31.02h1.25c.69-.01,1.18-.09,1.48-.23s.45-.52.45-1.1l.02-1.08c0-.37-.02-.68-.07-.95-.04-.26-.13-.45-.27-.58-.14-.12-.34-.21-.61-.25-.27-.04-.55-.06-.86-.06h-2.42c-.55.01-.97.07-1.26.18s-.45.35-.5.72-.07.67-.07.91v.54c0,.74.09,1.24.26,1.49.17.26.6.38,1.28.38Z"/><path d="M61.91,5.66c0,.33.11.53.33.62s.53.13.92.13h1.15s1.63-.04,1.63-.04c.45,0,.74-.06.88-.17s.2-.29.2-.53c0-.22-.08-.39-.23-.52-.16-.13-.45-.19-.89-.19h-.4l-4.01-.08c-.75,0-1.28-.19-1.59-.57-.31-.38-.46-.96-.46-1.75,0-.66.1-1.18.29-1.55.2-.37.55-.62,1.07-.75.52-.13,1.19-.21,2.03-.23.84-.02,1.4-.04,1.69-.04l1.7.02c.68,0,1.23.05,1.64.16.42.11.71.31.88.59s.28.55.33.78c.05.23.07.55.07.96h-2.39c0-.28-.08-.48-.25-.59-.16-.11-.39-.17-.69-.17h-1.18s-1.86.05-1.86.05c-.25,0-.46.05-.63.14s-.26.25-.26.46c0,.3.1.49.31.58.2.09.48.14.83.14h.25l1.63.02h1.61c1.03.01,1.78.17,2.25.5.47.32.7.97.7,1.95s-.21,1.59-.63,1.94c-.42.35-1.11.53-2.08.53l-3.12.08-1.63-.04c-.86,0-1.5-.14-1.91-.42s-.61-.84-.61-1.69v-.48h2.4v.13Z"/><path d="M74.24,8.09V2.09h-3.58V.08h9.58v2h-3.56v6h-2.44Z"/></svg>
    </a>
  </div>

  <div class="ope-topbar-right">
    <!-- Live avatars (overlapping) -->
    <div class="ope-topbar-avatars">
      {#if userAvatar}
        <img
          class="ope-topbar-avatar"
          src={userAvatar}
          alt={userName || 'You'}
          title={userName || 'You'}
        />
      {:else}
        <div
          class="ope-topbar-avatar"
          style="background: {getAvatarColor(userId)}"
          title={userName || 'You'}
        >
          {getInitials(userName)}
        </div>
      {/if}
      {#each activeUsers.filter(u => u.id !== userId).slice(0, 4) as user}
        <div
          class="ope-topbar-avatar"
          style="background: {getAvatarColor(user.id)}"
          title={user.name || 'User'}
        >
          {getInitials(user.name)}
        </div>
      {/each}
    </div>

    <!-- Save button -->
    <button
      class="ope-topbar-save"
      onclick={onsave}
      disabled={saving || !hasChanges}
    >
      {saving ? 'Saving...' : 'Save'}
    </button>

    <!-- Publish dropdown -->
    <div class="ope-topbar-publish-wrap">
      <button
        class="ope-topbar-publish"
        onclick={() => { publishOpen = !publishOpen; }}
      >
        Publish
        <svg width="10" height="10" viewBox="0 0 10 10" fill="none">
          <path d="M2.5 4L5 6.5L7.5 4" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
      </button>

      {#if publishOpen}
        <PublishDropdown
          {onpublish}
          {showToast}
          {apiUrl}
          {csrfToken}
          {pageId}
          onclose={() => { publishOpen = false; }}
        />
      {/if}
    </div>
  </div>
</div>

<style>
  .ope-topbar {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    height: 48px;
    background: #2D5A47;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 16px;
    z-index: 2147483645;
    pointer-events: auto;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Inter', sans-serif;
  }

  .ope-topbar-left {
    display: flex;
    align-items: center;
    gap: 12px;
  }

  .ope-topbar-logo {
    display: flex;
    align-items: center;
    text-decoration: none;
  }

  .ope-topbar-right {
    display: flex;
    align-items: center;
    gap: 10px;
  }

  .ope-topbar-avatars {
    display: flex;
    align-items: center;
    margin-right: 6px;
  }

  .ope-topbar-avatar {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 10px;
    font-weight: 600;
    letter-spacing: 0.02em;
    margin-left: -6px;
    border: 2px solid #2D5A47;
    cursor: default;
  }
  .ope-topbar-avatar:first-child {
    margin-left: 0;
  }

  .ope-topbar-me {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 11px;
    font-weight: 600;
    letter-spacing: 0.02em;
    text-decoration: none;
    margin-left: 8px;
    border: 2px solid rgba(255,255,255,0.3);
    transition: border-color 0.15s;
  }
  .ope-topbar-me:hover {
    border-color: white;
  }

  .ope-topbar-avatar-more {
    background: rgba(255, 255, 255, 0.2);
    font-size: 9px;
  }

  .ope-topbar-save {
    background: transparent;
    border: 1px solid rgba(255, 255, 255, 0.25);
    color: rgba(255, 255, 255, 0.9);
    font-size: 13px;
    font-weight: 500;
    padding: 6px 16px;
    border-radius: 6px;
    cursor: pointer;
    transition: all 0.15s;
  }
  .ope-topbar-save:hover:not(:disabled) {
    background: rgba(255, 255, 255, 0.1);
    border-color: rgba(255, 255, 255, 0.4);
    color: white;
  }
  .ope-topbar-save:disabled {
    opacity: 0.5;
    cursor: default;
  }

  .ope-topbar-publish-wrap {
    position: relative;
  }

  .ope-topbar-publish {
    background: #1E4535;
    border: none;
    color: white;
    font-size: 13px;
    font-weight: 500;
    padding: 6px 14px 6px 16px;
    border-radius: 6px;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 6px;
    transition: background 0.15s;
  }
  .ope-topbar-publish:hover {
    background: #173B2C;
  }

  @media (max-width: 640px) {
    .ope-topbar-pagename {
      max-width: 120px;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }
    .ope-topbar-avatars {
      display: none;
    }
  }
</style>
