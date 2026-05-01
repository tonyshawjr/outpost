<script>
  import { collections as collectionsApi, stats as statsApi } from '$lib/api.js';
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
    canManageSettings,
    canAccessCodeEditor,
    canBuildForms,
    canManageMembers,
    canManageChannels,
    isDeveloper,
    isAdmin,
    collectionGrants,
    featureFlags,
    darkMode,
  } from '$lib/stores.js';
  import { onMount } from 'svelte';
  import {
    Home, Globe, FileText, PenSquare, Upload, Settings, LifeBuoy,
    LayoutGrid, BookOpen, Calendar, SwatchBook, Code, FolderOpen,
    Columns3, ChevronRight, Search, Sun, Moon, LogOut, User,
    Layout, Rss, Lock, GitBranch, Workflow, Webhook,
    ArrowLeftRight, ClipboardList, Palette, Users, Shield, Zap,
    MessageSquare, Database, BarChart3, Clock, Archive, Sparkles
  } from 'lucide-svelte';
  import { auth } from '$lib/api.js';

  // --- Derived state from stores ---
  let route = $derived($currentRoute);
  let open = $derived($sidebarOpen);
  let colls = $derived($collectionsList);
  let activeCollSlug = $derived($currentCollectionSlug);
  let activeStatusFilter = $derived($currentStatusFilter);
  let data = $derived($statsData);
  let showSettings = $derived($canManageSettings);
  let showCode = $derived($canAccessCodeEditor);
  let showDeveloper = $derived($isDeveloper);
  let showAdmin = $derived($isAdmin);
  let showFormBuilder = $derived($canBuildForms);
  let showMembers = $derived($canManageMembers);
  let showChannels = $derived($canManageChannels);
  let grants = $derived($collectionGrants);
  let ff = $derived($featureFlags);
  let currentUser = $derived($user);
  let isDark = $derived($darkMode);

  function featureEnabled(key) {
    if (!ff) return true;
    return ff[key] !== false;
  }

  let hasSettingsAccess = $derived(showSettings);

  let filteredColls = $derived(
    grants === null ? colls : colls.filter(c => grants.includes(c.id))
  );

  // Sub-nav toggles
  let postsOpen = $state(false);
  let buildOpen = $state(false);
  let toolsOpen = $state(true);
  let membersOpen = $state(false);

  function getCollStats(slug) {
    if (!data?.collections) return null;
    return data.collections.find(c => c.slug === slug);
  }

  // Find pages and posts collections
  let pagesColl = $derived(filteredColls.find(c => c.slug === 'pages'));
  let postsColl = $derived(filteredColls.find(c => c.slug === 'posts' || c.slug === 'blog' || c.slug === 'news'));

  // User initials for avatar
  let userInitials = $derived(() => {
    if (!currentUser) return '?';
    const name = currentUser.display_name || currentUser.name || currentUser.first_name || currentUser.email || '';
    return name.charAt(0).toUpperCase();
  });

  let userAvatar = $derived(currentUser?.avatar_url || currentUser?.avatar || '');

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

  function nav(r, params = {}) {
    navigate(r, params);
    if (window.innerWidth < 768) sidebarOpen.set(false);
  }

  function isSubActive(collSlug, status) {
    return route === 'collection-items' && activeCollSlug === collSlug && activeStatusFilter === status;
  }

  let userMenuOpen = $state(false);

  function toggleUserMenu() {
    userMenuOpen = !userMenuOpen;
  }

  function toggleDarkMode() {
    darkMode.update(v => !v);
  }

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

  function togglePosts()  { postsOpen  = !postsOpen; }
  function toggleBuild()  { buildOpen  = !buildOpen; }
  function toggleTools()  { toolsOpen  = !toolsOpen; }
  function toggleMembers() { membersOpen = !membersOpen; }
</script>

<aside class="sb">
  <!-- Header -->
  <div class="sb-head">
    <div class="sb-logo">
      <span><span style="font-weight:800;">Outpost</span></span>
    </div>
    <button class="sb-search" onclick={() => searchOpen.set(true)} title="Search (⌘K)">
      <Search size={16} />
    </button>
  </div>

  <nav class="sb-nav">
    <!-- ====== TOP: dashboard + view site ====== -->
    <div class="sb-group">
      <a class="sb-item" class:active={route === 'dashboard'} onclick={() => nav('dashboard')}>
        <Home size={19} />
        <span>Dashboard</span>
      </a>
      <a class="sb-item" onclick={() => nav('view-site')}>
        <Globe size={19} />
        <span>View site</span>
      </a>
    </div>

    <!-- ====== CONTENT: pages, posts, media ====== -->
    <div class="sb-group">
      {#if pagesColl && featureEnabled('collections')}
        <a class="sb-item" class:active={route === 'pages' || (route === 'collection-items' && activeCollSlug === 'pages')} onclick={() => nav('pages')}>
          <FileText size={19} />
          <span>Pages</span>
        </a>
      {/if}
      {#if postsColl && featureEnabled('collections')}
        <a class="sb-item sb-toggle" onclick={togglePosts}>
          <PenSquare size={19} />
          <span>Posts</span>
          <span class="caret" class:open={postsOpen}><ChevronRight size={14} /></span>
        </a>
        <div class="sb-sub" class:open={postsOpen}>
          <a class="sb-item" class:active={isSubActive(postsColl.slug, 'draft')} onclick={() => nav('collection-items', { collectionSlug: postsColl.slug, statusFilter: 'draft' })}><span>Drafts</span></a>
          <a class="sb-item" class:active={isSubActive(postsColl.slug, 'scheduled')} onclick={() => nav('collection-items', { collectionSlug: postsColl.slug, statusFilter: 'scheduled' })}><span>Scheduled</span></a>
          <a class="sb-item" class:active={isSubActive(postsColl.slug, 'published')} onclick={() => nav('collection-items', { collectionSlug: postsColl.slug, statusFilter: 'published' })}><span>Published</span></a>
        </div>
      {/if}
      {#if featureEnabled('media')}
        <a class="sb-item" class:active={route === 'media'} onclick={() => nav('media')}>
          <Upload size={19} />
          <span>Media</span>
        </a>
      {/if}
      {#if featureEnabled('navigation')}
        <a class="sb-item" class:active={route === 'navigation'} onclick={() => nav('navigation')}>
          <Layout size={19} />
          <span>Navigation</span>
        </a>
      {/if}
      <a class="sb-item" class:active={route === 'globals'} onclick={() => nav('globals')}>
        <Sparkles size={19} />
        <span>Globals</span>
      </a>
    </div>

    <!-- ====== BUILD: page builder, design, code editor ====== -->
    {#if hasSettingsAccess || showDeveloper}
      <div class="sb-group">
        <div class="sb-label">Build</div>
        <a class="sb-item" class:active={route === 'page-builder'} onclick={() => nav('page-builder')}>
          <LayoutGrid size={19} />
          <span>Page Builder</span>
        </a>
        {#if hasSettingsAccess}
          <a class="sb-item" class:active={route === 'design'} onclick={() => nav('design')}>
            <SwatchBook size={19} />
            <span>Design</span>
          </a>
          <a class="sb-item" class:active={route === 'brand'} onclick={() => nav('brand')}>
            <Palette size={19} />
            <span>Brand</span>
          </a>
        {/if}
        {#if showCode}
          <a class="sb-item" class:active={route === 'code-editor'} onclick={() => nav('code-editor')}>
            <Code size={19} />
            <span>Code Editor</span>
          </a>
        {/if}
        {#if showFormBuilder}
          <a class="sb-item" class:active={route === 'forms' || route === 'form-builder' || route === 'form-submissions' || route === 'forms-list'} onclick={() => nav('forms-list')}>
            <ClipboardList size={19} />
            <span>Forms</span>
          </a>
        {/if}
      </div>
    {/if}

    <!-- ====== MEMBERS: lodge, members ====== -->
    {#if showMembers}
      <div class="sb-group">
        <div class="sb-label">Members</div>
        <a class="sb-item" class:active={route === 'lodge'} onclick={() => nav('lodge')}>
          <Lock size={19} />
          <span>Lodge</span>
        </a>
      </div>
    {/if}

    <!-- ====== TOOLS: developer / advanced ====== -->
    {#if showDeveloper || showAdmin}
      <div class="sb-group">
        <div class="sb-label">Tools</div>
        {#if showDeveloper}
          <a class="sb-item" class:active={route === 'collections'} onclick={() => nav('collections')}>
            <Columns3 size={19} />
            <span>Collections</span>
          </a>
          <a class="sb-item" class:active={route === 'folder-manager'} onclick={() => nav('folder-manager')}>
            <FolderOpen size={19} />
            <span>Folders</span>
          </a>
        {/if}
        {#if showChannels}
          <a class="sb-item" class:active={route === 'channels' || route === 'channel-builder'} onclick={() => nav('channels')}>
            <Rss size={19} />
            <span>Channels</span>
          </a>
        {/if}
        {#if showAdmin}
          <a class="sb-item" class:active={route === 'releases'} onclick={() => nav('releases')}>
            <GitBranch size={19} />
            <span>Releases</span>
          </a>
          <a class="sb-item" class:active={route === 'workflows'} onclick={() => nav('workflows')}>
            <Workflow size={19} />
            <span>Workflows</span>
          </a>
          <a class="sb-item" class:active={route === 'redirects'} onclick={() => nav('redirects')}>
            <ArrowLeftRight size={19} />
            <span>Redirects</span>
          </a>
          <a class="sb-item" class:active={route === 'review-tokens'} onclick={() => nav('review-tokens')}>
            <MessageSquare size={19} />
            <span>Review Links</span>
          </a>
          <a class="sb-item" class:active={route === 'analytics'} onclick={() => nav('analytics')}>
            <BarChart3 size={19} />
            <span>Analytics</span>
          </a>
          <a class="sb-item" class:active={route === 'calendar'} onclick={() => nav('calendar')}>
            <Calendar size={19} />
            <span>Calendar</span>
          </a>
          <a class="sb-item" class:active={route === 'backups'} onclick={() => nav('backups')}>
            <Archive size={19} />
            <span>Backups</span>
          </a>
        {/if}
      </div>
    {/if}

    <!-- ====== FOOTER: settings, help ====== -->
    <div class="sb-group" style="margin-top:auto">
      {#if hasSettingsAccess}
        <a class="sb-item" class:active={route === 'settings'} onclick={() => nav('settings', { section: 'general' })}>
          <Settings size={19} />
          <span>Settings</span>
        </a>
      {/if}
      <a class="sb-item" onclick={() => {}}>
        <LifeBuoy size={19} />
        <span>Help & Support</span>
      </a>
    </div>
  </nav>

  <!-- User card footer -->
  <div class="sb-foot-wrap sb-user-menu-wrap">
    <button class="sb-user-card" onclick={toggleUserMenu}>
      {#if userAvatar}
        <img class="sb-av-lg" src={userAvatar} alt="">
      {:else}
        <div class="sb-av-lg sb-av-initials">{userInitials()}</div>
      {/if}
      <div class="sb-user-info">
        <span class="sb-user-name">{currentUser?.display_name || currentUser?.username || 'User'}</span>
        <span class="sb-user-role">{currentUser?.role?.replace('_', ' ') || 'editor'}</span>
      </div>
      <ChevronRight size={14} class="sb-user-chevron" style="transform: rotate({userMenuOpen ? '270deg' : '90deg'}); transition: transform .2s; opacity: .3;" />
    </button>

    {#if userMenuOpen}
      <div class="sb-user-dropdown">
        <button class="sb-dropdown-item" onclick={() => { userMenuOpen = false; nav('user-profile', { userId: currentUser?.id }); }}>
          <User size={16} />
          <span>Your Profile</span>
        </button>
        <button class="sb-dropdown-item" onclick={() => { userMenuOpen = false; searchOpen.set(true); }}>
          <Search size={16} />
          <span>Search</span>
          <span class="sb-dropdown-kbd">⌘K</span>
        </button>
        <div class="sb-dropdown-divider"></div>
        <div class="sb-dropdown-item sb-dropdown-theme">
          <span class="sb-dropdown-theme-label">{isDark ? 'Dark' : 'Light'} mode</span>
          <button class="sb-theme-toggle" class:dark={isDark} onclick={(e) => { e.stopPropagation(); toggleDarkMode(); }}>
            <span class="sb-theme-track">
              <span class="sb-theme-icon" style="color: {!isDark ? '#fff' : 'var(--dim)'}"><Sun size={12} strokeWidth={2} /></span>
              <span class="sb-theme-icon" style="color: {isDark ? '#fff' : 'var(--dim)'}"><Moon size={12} strokeWidth={2} /></span>
              <span class="sb-theme-knob"></span>
            </span>
          </button>
        </div>
        <div class="sb-dropdown-divider"></div>
        <button class="sb-dropdown-item sb-dropdown-danger" onclick={handleSignOut}>
          <LogOut size={16} />
          <span>Sign out</span>
        </button>
      </div>
    {/if}
  </div>
</aside>

<style>
.sb{width:260px;background:var(--bg);display:flex;flex-direction:column;position:fixed;top:0;bottom:0;z-index:100;padding:24px 0 16px}
.sb-head{display:flex;align-items:center;justify-content:space-between;padding:0 20px;margin-bottom:32px;gap:8px}
.sb-logo{display:flex;align-items:center;gap:10px;font-size:17px;font-weight:700;color:var(--text);letter-spacing:-.02em;flex:1;min-width:0}
.sb-search{background:none;border:none;color:var(--dim);cursor:pointer;padding:4px;display:flex}.sb-search:hover{color:var(--sec)}
.sb-nav{flex:1;overflow-y:auto;display:flex;flex-direction:column}
.sb-group{padding:0 12px;margin-bottom:28px}
.sb-item{display:flex;align-items:center;gap:14px;padding:9px 12px;border-radius:6px;color:var(--sec);font-size:14px;font-weight:500;cursor:pointer;transition:all .12s;text-decoration:none;user-select:none;background:none;border:none;width:100%;text-align:left}
.sb-item:hover{color:var(--text)}.sb-item.active{color:var(--text);font-weight:600}
.sb-item svg{width:19px;height:19px;flex-shrink:0}
.sb-item .caret{margin-left:auto;opacity:.3;transition:transform .2s;flex-shrink:0;display:flex;align-items:center}
.sb-item .caret.open{transform:rotate(90deg)}
.sb-sub{padding-left:48px;overflow:hidden;transition:max-height .25s ease;max-height:0}
.sb-sub.open{max-height:300px}
.sb-sub .sb-item{padding:6px 0;font-size:14px;color:var(--dim);border-radius:0}.sb-sub .sb-item:hover{color:var(--sec)}
.sb-sub .sb-item.active{color:var(--text);font-weight:600}
.sb-label{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:var(--dim);padding:0 12px 6px;opacity:.5}
.sb-foot-wrap{padding:0 12px 4px;display:flex;flex-direction:column;gap:0;position:relative}
.sb-user-card{display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:8px;background:transparent;border:none;cursor:pointer;transition:all .12s;flex:1;min-width:0;text-align:left}
.sb-user-card:hover{background:var(--hover)}
.sb-av-lg{width:32px;height:32px;border-radius:50%;flex-shrink:0;object-fit:cover}
.sb-av-initials{background:var(--purple);color:#fff;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:600}
.sb-user-info{flex:1;min-width:0;display:flex;flex-direction:column;line-height:1.25;overflow:hidden}
.sb-user-name{font-size:13px;font-weight:600;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.sb-user-role{font-size:11px;color:var(--dim);text-transform:capitalize;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.sb-user-dropdown{position:absolute;bottom:calc(100% + 4px);left:12px;right:12px;background:var(--raised);border:1px solid var(--border-light, var(--hover));border-radius:10px;padding:6px;box-shadow:0 -4px 24px rgba(0,0,0,.18);z-index:200}
.sb-dropdown-item{display:flex;align-items:center;gap:10px;padding:8px 10px;border-radius:6px;background:none;border:none;color:var(--text);font-size:13px;cursor:pointer;width:100%;text-align:left;transition:background .12s}
.sb-dropdown-item:hover{background:var(--hover)}
.sb-dropdown-item span{flex:1}
.sb-dropdown-kbd{font-size:11px;color:var(--dim);font-family:var(--font-mono, ui-monospace, monospace);flex:0 0 auto !important}
.sb-dropdown-divider{height:1px;background:var(--hover);margin:4px 0}
.sb-dropdown-danger{color:#ef4444}.sb-dropdown-danger:hover{background:rgba(239,68,68,.08)}
.sb-dropdown-theme{display:flex;align-items:center;justify-content:space-between;padding:8px 10px;cursor:default}
.sb-dropdown-theme:hover{background:none}
.sb-dropdown-theme-label{font-size:13px;color:var(--text)}
.sb-theme-toggle{background:none;border:none;padding:0;cursor:pointer;display:inline-flex}
.sb-theme-track{position:relative;width:44px;height:22px;background:var(--hover);border-radius:11px;display:flex;align-items:center;padding:0 4px;transition:background .2s}
.sb-theme-toggle.dark .sb-theme-track{background:var(--purple)}
.sb-theme-icon{position:relative;z-index:2;display:flex;align-items:center;justify-content:center;width:14px;height:14px}
.sb-theme-icon:first-of-type{margin-right:auto}.sb-theme-icon:last-of-type{margin-left:auto}
.sb-theme-knob{position:absolute;top:2px;left:2px;width:18px;height:18px;background:#fff;border-radius:50%;transition:transform .2s;box-shadow:0 1px 3px rgba(0,0,0,.2);z-index:1}
.sb-theme-toggle.dark .sb-theme-knob{transform:translateX(22px)}
@media(max-width:768px){
  .sb{transform:translateX(-100%);transition:transform .25s}
  .sb.open{transform:translateX(0)}
}
</style>
