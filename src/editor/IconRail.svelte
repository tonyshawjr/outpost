<script>
  let {
    activeDrawer = 'edit',
    drawerOpen = true,
    ontoggle = () => {},
    onsave = () => {},
    userAvatar = '',
    userName = '',
    hasChanges = false,
    saving = false,
  } = $props();

  const topIcons = [
    { id: 'edit', label: 'Edit', svg: `<path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z" stroke="currentColor" stroke-width="1.5" fill="none" stroke-linecap="round" stroke-linejoin="round"/>` },
    { id: 'seo', label: 'SEO', svg: `<path d="M20.283 10.356h-8.327v3.451h4.792c-.446 2.193-2.313 3.453-4.792 3.453a5.27 5.27 0 0 1-5.279-5.28 5.27 5.27 0 0 1 5.279-5.279c1.259 0 2.397.447 3.29 1.178l2.6-2.599c-1.584-1.381-3.615-2.233-5.89-2.233a8.908 8.908 0 0 0-8.934 8.934 8.907 8.907 0 0 0 8.934 8.934c4.467 0 8.529-3.249 8.529-8.934 0-.528-.081-1.097-.202-1.625z" fill="none" stroke="currentColor" stroke-width="1.3"/>` },
    { id: 'settings', label: 'Settings', svg: `<circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="1.5" fill="none"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z" stroke="currentColor" stroke-width="1.5" fill="none"/>` },
    { id: 'ranger', label: 'Ranger AI', svg: `<path d="M12 3l1.5 4.5L18 9l-4.5 1.5L12 15l-1.5-4.5L6 9l4.5-1.5L12 3z" stroke="currentColor" stroke-width="1.5" fill="none" stroke-linejoin="round"/><path d="M19 13l.75 2.25L22 16l-2.25.75L19 19l-.75-2.25L16 16l2.25-.75L19 13z" stroke="currentColor" stroke-width="1.3" fill="none" stroke-linejoin="round"/><path d="M5 17l.5 1.5L7 19l-1.5.5L5 21l-.5-1.5L3 19l1.5-.5L5 17z" stroke="currentColor" stroke-width="1.2" fill="none" stroke-linejoin="round"/>` },
    { id: 'history', label: 'History', svg: `<circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="1.5" fill="none"/><polyline points="12 6 12 12 16 14" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>` },
    { id: 'comments', label: 'Comments', svg: `<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" stroke="currentColor" stroke-width="1.5" fill="none" stroke-linejoin="round"/>` },
  ];

  // History and comments go right after the top icons — no separate group
</script>

<div class="ope-iconrail">
  <div class="ope-iconrail-top">
    {#each topIcons as icon}
      <button
        class="ope-iconrail-btn"
        class:ope-iconrail-active={activeDrawer === icon.id && drawerOpen}
        title={icon.label}
        onclick={() => ontoggle(icon.id)}
      >
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none">{@html icon.svg}</svg>
      </button>
    {/each}
  </div>

  <div class="ope-iconrail-bottom">
    <button
      class="ope-iconrail-btn"
      class:ope-iconrail-active={activeDrawer === 'preview' && drawerOpen}
      title="Preview"
      onclick={() => ontoggle('preview')}
    >
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none">
        <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" stroke="currentColor" stroke-width="1.5" fill="none"/>
        <circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="1.5" fill="none"/>
      </svg>
    </button>

    <button
      class="ope-iconrail-btn ope-save-btn"
      class:ope-save-active={hasChanges}
      class:ope-save-saving={saving}
      title={saving ? 'Saving...' : hasChanges ? 'Save changes' : 'No changes'}
      onclick={onsave}
      disabled={!hasChanges || saving}
    >
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none">
        <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z" stroke="currentColor" stroke-width="1.5" fill="none" stroke-linejoin="round"/>
        <polyline points="17 21 17 13 7 13 7 21" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
        <polyline points="7 3 7 8 15 8" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
      </svg>
    </button>

    <a
      class="ope-iconrail-btn ope-admin-link"
      href="/outpost/admin/"
      title="Back to Admin"
    >
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none">
        <rect x="3" y="3" width="7" height="7" rx="1" stroke="currentColor" stroke-width="1.5" fill="none"/>
        <rect x="14" y="3" width="7" height="7" rx="1" stroke="currentColor" stroke-width="1.5" fill="none"/>
        <rect x="3" y="14" width="7" height="7" rx="1" stroke="currentColor" stroke-width="1.5" fill="none"/>
        <rect x="14" y="14" width="7" height="7" rx="1" stroke="currentColor" stroke-width="1.5" fill="none"/>
      </svg>
    </a>

    <div class="ope-iconrail-avatar" title={userName || 'User'}>
      {#if userAvatar}
        <img src={userAvatar} alt="" />
      {:else}
        <span>{(userName || 'U').charAt(0).toUpperCase()}</span>
      {/if}
    </div>
  </div>
</div>

<style>
  .ope-iconrail {
    position: fixed;
    top: 0;
    right: 0;
    width: 56px;
    bottom: 0;
    background: linear-gradient(180deg, #2D5A47 0%, #234a3a 50%, #1f4435 100%);
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    align-items: stretch;
    padding: 12px 0;
    z-index: 2147483646;
    pointer-events: auto;
  }

  .ope-iconrail-top,
  .ope-iconrail-mid,
  .ope-iconrail-bottom {
    display: flex;
    flex-direction: column;
    align-items: stretch;
    gap: 0;
  }

  .ope-iconrail-btn {
    width: 100%;
    height: 44px;
    display: flex;
    align-items: center;
    justify-content: center;
    border: none;
    border-top: 1px solid rgba(255,255,255,0.1);
    background: transparent;
    color: rgba(255,255,255,0.6);
    border-radius: 0;
    cursor: pointer;
    transition: all 0.15s;
    padding: 0;
  }
  .ope-iconrail-top .ope-iconrail-btn:first-child {
    border-top: none;
  }
  .ope-iconrail-bottom .ope-iconrail-btn:first-child {
    border-top: none;
  }

  .ope-iconrail-btn:hover {
    background: rgba(255,255,255,0.1);
    color: #fff;
  }

  .ope-iconrail-active {
    background: rgba(255,255,255,0.15);
    color: #fff;
  }

  .ope-save-btn {
    color: rgba(255,255,255,0.3);
  }
  .ope-save-btn:disabled {
    cursor: default;
    opacity: 0.4;
  }
  .ope-save-active {
    color: #fff;
  }
  .ope-save-saving {
    animation: ope-pulse 1s infinite;
  }
  @keyframes ope-pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.4; }
  }

  .ope-admin-link {
    text-decoration: none;
    border-top: 1px solid rgba(255,255,255,0.1);
  }

  .ope-iconrail-avatar {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    overflow: hidden;
    background: rgba(255,255,255,0.2);
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 8px auto 0;
    border: 2px solid rgba(255,255,255,0.3);
  }
  .ope-iconrail-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
  }
  .ope-iconrail-avatar span {
    font-size: 13px;
    font-weight: 600;
    color: #fff;
  }

  @media (max-width: 640px) {
    .ope-iconrail {
      top: auto;
      bottom: 0;
      left: 0;
      right: 0;
      width: 100%;
      height: 56px;
      flex-direction: row;
      padding: 0 8px;
      border-left: none;
      border-top: 1px solid #E5E7EB;
    }
    .ope-iconrail-top,
    .ope-iconrail-mid,
    .ope-iconrail-bottom { flex-direction: row; }
    .ope-iconrail-avatar { margin: 0 0 0 8px; }
  }
</style>
