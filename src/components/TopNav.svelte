<script>
  import { onMount } from 'svelte';
  import {
    currentRoute, navigate, user, searchOpen, darkMode,
    canManageSettings, canAccessCodeEditor, canBuildForms,
    canManageMembers, canManageChannels, isDeveloper, isAdmin, featureFlags,
  } from '$lib/stores.js';
  import { auth } from '$lib/api.js';
  import {
    Home, Globe, FileText, Layout, Sparkles, SwatchBook, Palette, ArrowLeftRight,
    LayoutGrid, Box, PenSquare, Mail, Calendar, GitBranch, MessageSquare, ClipboardList,
    Columns3, Database, FolderOpen, BarChart3, Rss, Workflow, Archive, Upload, Lock,
    Code, Search, Sun, Moon, Settings, ChevronDown, LifeBuoy,
  } from 'lucide-svelte';

  let route = $derived($currentRoute);
  let showSettings = $derived($canManageSettings);
  let showCode = $derived($canAccessCodeEditor);
  let showDeveloper = $derived($isDeveloper);
  let showAdmin = $derived($isAdmin);
  let showFormBuilder = $derived($canBuildForms);
  let showMembers = $derived($canManageMembers);
  let showChannels = $derived($canManageChannels);
  let ff = $derived($featureFlags);
  let currentUser = $derived($user);
  let isDark = $derived($darkMode);

  function feat(key) { return !ff || ff[key] !== false; }

  let groups = $derived([
    { label: 'Dashboard', icon: Home, route: 'dashboard' },
    {
      label: 'Site', icon: Globe, items: [
        { label: 'Pages', route: 'pages', icon: FileText, on: true, activeAlso: ['node-builder'] },
        { label: 'Navigation', route: 'navigation', icon: Layout, on: feat('navigation') },
        { label: 'Globals', route: 'globals', icon: Sparkles, on: true },
        { label: 'Design', route: 'design', icon: SwatchBook, on: showSettings },
        { label: 'Brand', route: 'brand', icon: Palette, on: showSettings },
        { label: 'Redirects', route: 'redirects', icon: ArrowLeftRight, on: showAdmin },
        { label: 'View site', route: 'view-site', icon: Globe, on: true },
      ],
    },
    {
      label: 'Content', icon: PenSquare, items: [
        { label: 'Page Builder', route: 'page-builder', icon: LayoutGrid, on: showSettings || showDeveloper },
        { label: 'Visual Builder', route: 'node-builder', icon: Box, on: showSettings || showDeveloper },
        { label: 'Editorial AI', route: 'editorial-ai', icon: Sparkles, on: showAdmin },
        { label: 'Newsletter', route: 'newsletter', icon: Mail, on: showAdmin },
        { label: 'Calendar', route: 'calendar', icon: Calendar, on: showAdmin },
        { label: 'Releases', route: 'releases', icon: GitBranch, on: showAdmin },
        { label: 'Review Links', route: 'review-tokens', icon: MessageSquare, on: showAdmin },
        { label: 'Forms', route: 'forms-list', icon: ClipboardList, on: showFormBuilder, activeAlso: ['forms', 'form-builder', 'form-submissions'] },
      ],
    },
    {
      label: 'Data', icon: Database, items: [
        { label: 'Collections', route: 'collections', icon: Columns3, on: showDeveloper },
        { label: 'Field Presets', route: 'field-presets', icon: Database, on: showDeveloper },
        { label: 'Folders', route: 'folder-manager', icon: FolderOpen, on: showDeveloper },
        { label: 'Analytics', route: 'analytics', icon: BarChart3, on: showAdmin, activeAlso: ['analytics-events', 'analytics-goals', 'analytics-search', 'analytics-content', 'analytics-funnels'] },
        { label: 'Channels', route: 'channels', icon: Rss, on: showChannels, activeAlso: ['channel-builder'] },
        { label: 'Workflows', route: 'workflows', icon: Workflow, on: showAdmin },
        { label: 'Backups', route: 'backups', icon: Archive, on: showAdmin },
        { label: 'Code Editor', route: 'code-editor', icon: Code, on: showCode },
      ],
    },
    { label: 'Media', icon: Upload, route: 'media', on: feat('media') },
    { label: 'Members', icon: Lock, route: 'lodge', on: showMembers },
  ]);

  let visibleGroups = $derived(groups.filter((g) => {
    if (g.route) return g.on === undefined ? true : g.on;
    return (g.items || []).some((i) => i.on);
  }));

  let openGroup = $state(null);

  function groupActive(g) {
    if (g.route) return route === g.route;
    return (g.items || []).some((i) => i.on && (route === i.route || (i.activeAlso || []).includes(route)));
  }

  function go(r) {
    navigate(r);
    openGroup = null;
  }

  function toggleGroup(label) {
    openGroup = openGroup === label ? null : label;
  }

  let userMenuOpen = $state(false);
  function toggleDark() { darkMode.update((v) => !v); }
  async function signOut() { try { await auth.logout(); } catch {} window.location.reload(); }

  let initials = $derived.by(() => {
    const n = currentUser?.display_name || currentUser?.name || currentUser?.email || '?';
    return String(n).charAt(0).toUpperCase();
  });

  $effect(() => {
    if (openGroup === null && !userMenuOpen) return;
    function handler(e) {
      if (!e.target.closest('.tn-group') && !e.target.closest('.tn-menu')) openGroup = null;
      if (!e.target.closest('.tn-user')) userMenuOpen = false;
    }
    setTimeout(() => document.addEventListener('click', handler), 0);
    return () => document.removeEventListener('click', handler);
  });
</script>

<header class="topnav">
  <div class="tn-brand" onclick={() => go('dashboard')} role="button" tabindex="0"
    onkeydown={(e) => { if (e.key === 'Enter') go('dashboard'); }}>Outpost</div>

  <nav class="tn-nav" aria-label="Primary">
    {#each visibleGroups as g (g.label)}
      {#if g.route}
        <button class="tn-top" class:active={groupActive(g)} aria-current={groupActive(g) ? 'page' : undefined} onclick={() => go(g.route)}>
          <g.icon size={16} aria-hidden="true" />
          <span>{g.label}</span>
        </button>
      {:else}
        <div class="tn-group">
          <button class="tn-top" class:active={groupActive(g)} aria-expanded={openGroup === g.label} onclick={() => toggleGroup(g.label)}>
            <g.icon size={16} aria-hidden="true" />
            <span>{g.label}</span>
            <ChevronDown size={13} aria-hidden="true" class="tn-caret {openGroup === g.label ? 'up' : ''}" />
          </button>
          {#if openGroup === g.label}
            <div class="tn-menu" role="menu">
              {#each g.items.filter((i) => i.on) as it (it.route)}
                <button class="tn-menu-item" class:active={route === it.route || (it.activeAlso || []).includes(route)} role="menuitem" onclick={() => go(it.route)}>
                  <it.icon size={15} aria-hidden="true" />
                  <span>{it.label}</span>
                </button>
              {/each}
            </div>
          {/if}
        </div>
      {/if}
    {/each}
  </nav>

  <div class="tn-right">
    <button class="tn-icon" onclick={() => searchOpen.set(true)} title="Search (⌘K)" aria-label="Search">
      <Search size={17} aria-hidden="true" />
    </button>
    <button class="tn-icon" onclick={toggleDark} aria-label="Toggle dark mode">
      {#if isDark}<Sun size={17} aria-hidden="true" />{:else}<Moon size={17} aria-hidden="true" />{/if}
    </button>
    {#if showSettings}
      <button class="tn-icon" class:active={route === 'settings'} onclick={() => go('settings')} title="Settings" aria-label="Settings">
        <Settings size={17} aria-hidden="true" />
      </button>
    {/if}
    <div class="tn-user">
      <button class="tn-avatar" onclick={() => (userMenuOpen = !userMenuOpen)} aria-expanded={userMenuOpen} aria-label="Account menu">
        {initials}
      </button>
      {#if userMenuOpen}
        <div class="tn-menu tn-user-menu" role="menu">
          <div class="tn-user-name">{currentUser?.display_name || currentUser?.email || 'Account'}</div>
          <button class="tn-menu-item" role="menuitem" onclick={() => go('help')}><LifeBuoy size={15} aria-hidden="true" /><span>Help &amp; Support</span></button>
          <button class="tn-menu-item danger" role="menuitem" onclick={signOut}><span>Sign out</span></button>
        </div>
      {/if}
    </div>
  </div>
</header>

<style>
  .topnav {
    display: flex;
    align-items: center;
    gap: 14px;
    height: 52px;
    padding: 0 16px;
    background: var(--raised, #17171b);
    border-bottom: 1px solid var(--border);
    flex-shrink: 0;
  }
  .tn-brand { font-weight: 800; font-size: 16px; color: var(--text); cursor: pointer; padding-right: 6px; }
  .tn-brand:focus-visible { outline: 2px solid var(--purple); outline-offset: 2px; border-radius: 4px; }

  .tn-nav { display: flex; align-items: center; gap: 2px; flex: 1; min-width: 0; }
  .tn-group { position: relative; }
  .tn-top {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 7px 12px;
    border: none;
    border-radius: 8px;
    background: transparent;
    color: var(--sec);
    font-size: 13.5px;
    font-weight: 500;
    cursor: pointer;
    white-space: nowrap;
  }
  .tn-top:hover { background: var(--hover); color: var(--text); }
  .tn-top.active { background: var(--purple-bg, var(--hover)); color: var(--purple-soft, var(--purple)); }
  .tn-top:focus-visible { outline: 2px solid var(--purple); outline-offset: 1px; }
  .tn-top :global(.tn-caret) { transition: transform 0.15s; opacity: 0.7; }
  .tn-top :global(.tn-caret.up) { transform: rotate(180deg); }

  .tn-menu {
    position: absolute;
    top: calc(100% + 6px);
    left: 0;
    min-width: 210px;
    padding: 6px;
    background: var(--raised, #17171b);
    border: 1px solid var(--border);
    border-radius: 10px;
    box-shadow: 0 12px 34px rgba(0, 0, 0, 0.35);
    z-index: 100;
    display: flex;
    flex-direction: column;
    gap: 1px;
  }
  .tn-menu-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px 10px;
    border: none;
    border-radius: 7px;
    background: transparent;
    color: var(--sec);
    font-size: 13px;
    text-align: left;
    cursor: pointer;
    width: 100%;
  }
  .tn-menu-item:hover { background: var(--hover); color: var(--text); }
  .tn-menu-item.active { background: var(--purple-bg, var(--hover)); color: var(--purple-soft, var(--purple)); }
  .tn-menu-item:focus-visible { outline: 2px solid var(--purple); outline-offset: -2px; }
  .tn-menu-item.danger:hover { color: var(--red); }
  .tn-menu-item :global(svg) { flex-shrink: 0; color: var(--dim); }

  .tn-right { display: flex; align-items: center; gap: 4px; margin-left: auto; }
  .tn-icon {
    display: inline-flex;
    padding: 7px;
    border: none;
    border-radius: 8px;
    background: transparent;
    color: var(--sec);
    cursor: pointer;
  }
  .tn-icon:hover { background: var(--hover); color: var(--text); }
  .tn-icon.active { color: var(--purple-soft, var(--purple)); }
  .tn-icon:focus-visible { outline: 2px solid var(--purple); outline-offset: 1px; }

  .tn-user { position: relative; margin-left: 4px; }
  .tn-avatar {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    border: none;
    background: var(--purple);
    color: #fff;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
  }
  .tn-avatar:focus-visible { outline: 2px solid var(--purple-soft, var(--purple)); outline-offset: 2px; }
  .tn-user-menu { left: auto; right: 0; min-width: 200px; }
  .tn-user-name { padding: 6px 10px 8px; font-size: 12px; color: var(--dim); border-bottom: 1px solid var(--border); margin-bottom: 4px; }

  @media (max-width: 860px) {
    .tn-top span { display: none; }
    .tn-brand { display: none; }
  }
</style>
