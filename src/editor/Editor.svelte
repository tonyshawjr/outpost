<script>
  import TopBar from './TopBar.svelte';
  import IconRail from './IconRail.svelte';
  import EditDrawer from './drawers/EditDrawer.svelte';
  import SEODrawer from './drawers/SEODrawer.svelte';
  import SettingsDrawer from './drawers/SettingsDrawer.svelte';
  import RangerDrawer from './drawers/RangerDrawer.svelte';
  import HistoryDrawer from './drawers/HistoryDrawer.svelte';
  import CommentsDrawer from './drawers/CommentsDrawer.svelte';
  import PreviewPill from './PreviewPill.svelte';
  import PublishDropdown from './PublishDropdown.svelte';
  import Toast from './Toast.svelte';

  const ctx = window.__OUTPOST_EDITOR__ || window.__OPE || {};

  let activeDrawer = $state(null);
  let drawerOpen = $state(false);
  let hasChanges = $state(false);
  let saving = $state(false);
  let publishing = $state(false);
  let previewMode = $state(false);
  let overlayVisible = $state(true);
  let toastMessage = $state('');
  let toastVisible = $state(false);

  // No page manipulation — drawer overlays on top, page stays as-is

  let pageData = $state(null);
  let fields = $state([]);
  let fieldMap = $state({});

  // Active users for live avatars
  let activeUsers = $state([]);

  // Bridge click-to-edit state
  let bridgeHighlightedField = $state(null);
  let bridgeHighlightedBlock = $state(null);

  const pageName = $derived(
    ctx.pageName || pageData?.title || ctx.pagePath || '/'
  );

  const drawerTitles = {
    edit: 'Edit',
    seo: 'SEO',
    settings: 'Settings',
    ranger: 'Ranger',
    history: 'History',
    comments: 'Comments',
  };

  function toggleDrawer(id) {
    if (id === 'preview') {
      enterPreview();
      return;
    }
    if (activeDrawer === id && drawerOpen) {
      drawerOpen = false;
    } else {
      activeDrawer = id;
      drawerOpen = true;
    }
  }

  function closeDrawer() {
    drawerOpen = false;
  }

  // Sync edit mode with the bridge — only intercept clicks when Edit drawer is open
  let isEditMode = $derived(activeDrawer === 'edit' && drawerOpen);
  $effect(() => {
    window.postMessage({ type: 'outpost-edit-mode', active: isEditMode }, '*');
  });

  function enterPreview() {
    previewMode = true;
    overlayVisible = false;
  }

  function exitPreview() {
    previewMode = false;
    overlayVisible = true;
  }

  function showToast(msg) {
    toastMessage = msg;
    toastVisible = true;
    setTimeout(() => { toastVisible = false; }, 3000);
  }

  function handleSave() {
    window.dispatchEvent(new Event('outpost-save'));
  }

  async function handlePublish() {
    if (publishing) return;
    publishing = true;
    try {
      const resp = await fetch((ctx.apiUrl || '/outpost/api.php') + '?action=pages&id=' + (ctx.pageId || ''), {
        method: 'PUT',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': ctx.csrfToken || ctx.csrf || '',
        },
        credentials: 'include',
        body: JSON.stringify({ status: 'published' }),
      });
      if (resp.ok) {
        hasChanges = false;
        showToast('Published');
      }
    } catch (err) {
      console.error('[OPE] Publish error:', err);
      showToast('Publish failed');
    } finally {
      publishing = false;
    }
  }

  // Load page data on mount
  $effect(() => {
    loadPageData();
    pollActiveUsers();
  });

  async function loadPageData() {
    try {
      const path = ctx.pagePath || window.location.pathname;
      const resp = await fetch((ctx.apiUrl || '/outpost/api.php') + '?action=pages&path=' + encodeURIComponent(path), {
        credentials: 'include',
        headers: { 'X-CSRF-Token': ctx.csrfToken || ctx.csrf || '' },
      });
      if (resp.ok) {
        const data = await resp.json();
        pageData = data.page || data;
      }
    } catch (err) {
      console.error('[OPE] Failed to load page data:', err);
    }
  }

  let pollTimer = null;
  function pollActiveUsers() {
    async function poll() {
      try {
        const resp = await fetch((ctx.apiUrl || '/outpost/api.php') + '?action=editor/active-users&page_id=' + (ctx.pageId || ''), {
          credentials: 'include',
          headers: { 'X-CSRF-Token': ctx.csrfToken || ctx.csrf || '' },
        });
        if (resp.ok) {
          const data = await resp.json();
          activeUsers = data.users || [];
        }
      } catch {
        // silently fail
      }
    }
    poll();
    pollTimer = setInterval(poll, 30000);
    return () => clearInterval(pollTimer);
  }

  // Global keyboard shortcuts
  function handleKeydown(e) {
    if ((e.metaKey || e.ctrlKey) && e.key === 's') {
      e.preventDefault();
      handleSave();
    }
    if (e.key === 'Escape' && previewMode) {
      exitPreview();
    }
  }

  // Bridge: listen for click-to-edit postMessage events from the bridge script
  function handleBridgeMessage(e) {
    if (!e.data || typeof e.data !== 'object') return;

    if (e.data.type === 'outpost-field-click') {
      // Open the edit drawer and highlight the clicked field
      activeDrawer = 'edit';
      drawerOpen = true;
      bridgeHighlightedField = e.data.field;
      bridgeHighlightedBlock = e.data.block || null;
      // Reset after the effect has processed it
      setTimeout(() => { bridgeHighlightedField = null; }, 100);
    }

    if (e.data.type === 'outpost-block-click') {
      // Open the edit drawer and navigate to the block's section
      activeDrawer = 'edit';
      drawerOpen = true;
      bridgeHighlightedBlock = e.data.block;
      bridgeHighlightedField = null;
      // Reset after the effect has processed it
      setTimeout(() => { bridgeHighlightedBlock = null; }, 100);
    }
  }

  // Mount bridge message listener
  $effect(() => {
    window.addEventListener('message', handleBridgeMessage);
    return () => window.removeEventListener('message', handleBridgeMessage);
  });

  // Warn before navigating away with unsaved changes
  $effect(() => {
    function handleBeforeUnload(e) {
      if (hasChanges) {
        e.preventDefault();
        e.returnValue = '';
      }
    }
    window.addEventListener('beforeunload', handleBeforeUnload);
    return () => window.removeEventListener('beforeunload', handleBeforeUnload);
  });
</script>

<svelte:window onkeydown={handleKeydown} />

{#if overlayVisible}
  <div class="ope-overlay" class:ope-overlay-hidden={!overlayVisible}>
    <IconRail
      {activeDrawer}
      {drawerOpen}
      ontoggle={toggleDrawer}
      onsave={handleSave}
      userAvatar={ctx.userAvatar}
      userName={ctx.userName}
      {hasChanges}
      {saving}
    />

    {#if drawerOpen && activeDrawer && activeDrawer !== 'preview'}
      <div class="ope-panel-area">
        <button class="ope-panel-close-tab" onclick={closeDrawer} title="Close">
          <svg width="14" height="14" viewBox="0 0 16 16" fill="none">
            <path d="M4 4l8 8M12 4l-8 8" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
          </svg>
        </button>
        <div class="ope-panel-header">
          <span class="ope-panel-title">{drawerTitles[activeDrawer] || ''}</span>
        </div>
        <div class="ope-panel-content">
          {#if activeDrawer === 'edit'}
            <EditDrawer
              {fields}
              {fieldMap}
              pageId={ctx.pageId}
              {pageName}
              apiUrl={ctx.apiUrl || '/outpost/api.php'}
              csrfToken={ctx.csrfToken || ctx.csrf || ''}
              highlightedField={bridgeHighlightedField}
              highlightedBlock={bridgeHighlightedBlock}
              onchanged={(changed) => { hasChanges = changed; }}
            />
          {:else if activeDrawer === 'seo'}
            <SEODrawer
              {pageData}
              pageId={ctx.pageId}
              pagePath={ctx.pagePath || window.location.pathname}
              apiUrl={ctx.apiUrl || '/outpost/api.php'}
              csrfToken={ctx.csrfToken || ctx.csrf || ''}
              {showToast}
            />
          {:else if activeDrawer === 'settings'}
            <SettingsDrawer
              {pageData}
              pageId={ctx.pageId}
              themeSlug={ctx.themeSlug || ''}
              apiUrl={ctx.apiUrl || '/outpost/api.php'}
              csrfToken={ctx.csrfToken || ctx.csrf || ''}
              {showToast}
            />
          {:else if activeDrawer === 'ranger'}
            <RangerDrawer />
          {:else if activeDrawer === 'history'}
            <HistoryDrawer
              pageId={ctx.pageId}
              apiUrl={ctx.apiUrl || '/outpost/api.php'}
              csrfToken={ctx.csrfToken || ctx.csrf || ''}
            />
          {:else if activeDrawer === 'comments'}
            <CommentsDrawer
              pageId={ctx.pageId}
              apiUrl={ctx.apiUrl || '/outpost/api.php'}
              csrfToken={ctx.csrfToken || ctx.csrf || ''}
              userId={ctx.userId}
              userName={ctx.userName}
            />
          {/if}
        </div>
      </div>
    {/if}
  </div>
{/if}

{#if previewMode}
  <PreviewPill onexit={exitPreview} />
{/if}

{#if toastVisible}
  <Toast message={toastMessage} />
{/if}

<style>
  .ope-overlay {
    position: fixed;
    inset: 0;
    z-index: 2147483640;
    pointer-events: none;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Inter', sans-serif;
    transition: opacity 0.2s ease;
  }
  .ope-overlay :global(*) {
    box-sizing: border-box;
  }
  .ope-overlay-hidden {
    opacity: 0;
    pointer-events: none;
  }

  /* Panel area — white bg, left border stroke */
  .ope-panel-area {
    position: fixed;
    top: 0;
    right: 56px;
    bottom: 0;
    width: 400px;
    background: #f0f5f3;
    border-left: 1px solid #d4e0da;
    z-index: 2147483643;
    pointer-events: auto;
    display: flex;
    flex-direction: column;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Inter', sans-serif;
    overflow: visible;
    animation: ope-drawer-in 0.3s cubic-bezier(0.22, 1, 0.36, 1);
  }
  @keyframes ope-drawer-in {
    from { transform: translateX(100%); }
    to { transform: translateX(0); }
  }
  /* topography removed — clean light green */
  .ope-panel-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    height: 44px;
    padding: 0 20px;
    flex-shrink: 0;
    position: relative;
    z-index: 1;
  }

  .ope-panel-title {
    font-size: 16px;
    font-weight: 600;
    color: #111827;
    letter-spacing: -0.01em;
  }

  .ope-panel-close-tab {
    position: absolute;
    left: -32px;
    top: 16px;
    width: 32px;
    height: 36px;
    display: flex;
    align-items: center;
    justify-content: center;
    border: none;
    background: #2D5A47;
    color: rgba(255,255,255,0.8);
    border-radius: 6px 0 0 6px;
    cursor: pointer;
    padding: 0;
    transition: all 0.15s;
    z-index: 1;
  }
  .ope-panel-close-tab:hover {
    background: #1f4435;
    color: #fff;
  }

  .ope-panel-content {
    flex: 1;
    overflow-y: auto;
    overflow-x: hidden;
    padding: 0 4px;
    scrollbar-width: none;
    -ms-overflow-style: none;
    position: relative;
    z-index: 1;
  }
  .ope-panel-content::-webkit-scrollbar {
    display: none; /* Chrome/Safari */
  }

  /* No background change — page stays normal, just pushed left */

  @media (max-width: 768px) {
    .ope-panel-area {
      right: 0;
      width: 100%;
      top: 48px;
      bottom: 56px;
    }
  }

  @media (min-width: 769px) and (max-width: 1024px) {
    .ope-panel-area {
      width: 360px;
    }
  }
</style>
