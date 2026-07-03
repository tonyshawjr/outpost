<script>
  import { onMount } from 'svelte';
  import {
    currentRoute, currentCollectionSlug, navigate, user, searchOpen, darkMode,
    canManageSettings, canAccessCodeEditor, canBuildForms,
    canManageMembers, canManageChannels, isDeveloper, isAdmin, featureFlags,
    collectionsList, collectionGrants,
  } from '$lib/stores.js';
  import { auth, collections as collectionsApi } from '$lib/api.js';
  import {
    Home, Globe, FileText, Layout, Sparkles, SwatchBook, Palette, ArrowLeftRight,
    LayoutGrid, Box, PenSquare, Mail, Calendar, GitBranch, MessageSquare, ClipboardList,
    Columns3, Database, FolderOpen, BarChart3, Rss, Workflow, Archive, Upload, Lock,
    Code, Search, Sun, Moon, Settings, ChevronDown, LifeBuoy, Image, Utensils, Briefcase, Wrench,
  } from 'lucide-svelte';

  let route = $derived($currentRoute);
  let activeColl = $derived($currentCollectionSlug);
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
  let allColls = $derived($collectionsList || []);
  let grants = $derived($collectionGrants);

  function feat(key) { return !ff || ff[key] !== false; }

  onMount(async () => {
    try {
      const data = await collectionsApi.list();
      collectionsList.set(data.collections || []);
    } catch (e) {}
  });

  function collIcon(slug) {
    const s = String(slug || '');
    if (s.includes('gallery') || s.includes('image') || s.includes('photo')) return Image;
    if (s.includes('menu') || s.includes('food') || s.includes('dish')) return Utensils;
    if (s.includes('project') || s.includes('work') || s.includes('portfolio')) return Briefcase;
    if (s.includes('service')) return Wrench;
    if (s.includes('post') || s.includes('blog') || s.includes('news') || s.includes('article')) return PenSquare;
    return Columns3;
  }

  let contentCollections = $derived(
    (grants == null ? allColls : allColls.filter((c) => grants.includes(c.id)))
      .filter((c) => c.slug !== 'pages')
      .map((c) => ({ label: c.name || c.label || c.slug, slug: c.slug, icon: collIcon(c.slug) }))
  );

  let groups = $derived([
    { label: 'Dashboard', icon: Home, route: 'dashboard' },
    {
      label: 'Site', icon: Globe, items: [
        { label: 'Pages', route: 'pages', icon: FileText, on: true, activeAlso: ['node-builder', 'page-new', 'page-import'] },
        { label: 'Navigation', route: 'navigation', icon: Layout, on: feat('navigation') },
        { label: 'Globals', route: 'globals', icon: Sparkles, on: true },
        { label: 'Design', route: 'design', icon: SwatchBook, on: showSettings },
        { label: 'Brand', route: 'brand', icon: Palette, on: showSettings },
        { label: 'Redirects', route: 'redirects', icon: ArrowLeftRight, on: showAdmin },
        { label: 'View site', route: 'view-site', icon: Globe, on: true },
      ],
    },
    {
      label: 'Content', icon: PenSquare, mega: true, sections: [
        {
          title: 'Collections',
          items: contentCollections.map((c) => ({
            label: c.label, icon: c.icon, on: feat('collections'),
            route: 'collection-items', collectionSlug: c.slug,
          })),
        },
        {
          title: 'Create & manage',
          items: [
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

  function sectionsOf(g) {
    if (g.sections) return g.sections.map((s) => ({ ...s, items: s.items.filter((i) => i.on) })).filter((s) => s.items.length);
    return [{ title: null, items: (g.items || []).filter((i) => i.on) }];
  }

  let visibleGroups = $derived(groups.filter((g) => {
    if (g.route) return g.on === undefined ? true : g.on;
    return sectionsOf(g).length > 0;
  }));

  let openGroup = $state(null);

  function itemActive(i) {
    if (i.collectionSlug) return route === 'collection-items' && activeColl === i.collectionSlug;
    return route === i.route || (i.activeAlso || []).includes(route);
  }

  function groupActive(g) {
    if (g.route) return route === g.route;
    return sectionsOf(g).some((s) => s.items.some((i) => itemActive(i)));
  }

  function go(i) {
    if (i.collectionSlug) navigate('collection-items', { collectionSlug: i.collectionSlug });
    else navigate(typeof i === 'string' ? i : i.route);
    openGroup = null;
  }

  function toggleGroup(label) { openGroup = openGroup === label ? null : label; }

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
    function onKey(e) { if (e.key === 'Escape') { openGroup = null; userMenuOpen = false; } }
    setTimeout(() => document.addEventListener('click', handler), 0);
    document.addEventListener('keydown', onKey);
    return () => { document.removeEventListener('click', handler); document.removeEventListener('keydown', onKey); };
  });
</script>

<header class="topnav">
  <div class="tn-brand" onclick={() => go('dashboard')} role="button" tabindex="0"
    onkeydown={(e) => { if (e.key === 'Enter') go('dashboard'); }}>Outpost</div>

  <nav class="tn-nav" aria-label="Primary">
    {#each visibleGroups as g (g.label)}
      {#if g.route}
        <button class="tn-top" class:active={groupActive(g)} aria-current={groupActive(g) ? 'page' : undefined} onclick={() => go(g)}>
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
            <div class="tn-menu" class:mega={g.mega} role="menu">
              {#each sectionsOf(g) as sec (sec.title)}
                <div class="tn-col">
                  {#if sec.title}<div class="tn-sec-label">{sec.title}</div>{/if}
                  {#each sec.items as it (it.collectionSlug || it.route)}
                    <button class="tn-menu-item" class:active={itemActive(it)} role="menuitem" onclick={() => go(it)}>
                      <span class="tn-ic"><it.icon size={15} aria-hidden="true" /></span>
                      <span>{it.label}</span>
                    </button>
                  {/each}
                </div>
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
          <div class="tn-col">
            <div class="tn-user-name">{currentUser?.display_name || currentUser?.email || 'Account'}</div>
            <button class="tn-menu-item" role="menuitem" onclick={() => go('user-profile')}><span class="tn-ic"><Settings size={15} aria-hidden="true" /></span><span>Profile</span></button>
            <button class="tn-menu-item" role="menuitem" onclick={() => go('help')}><span class="tn-ic"><LifeBuoy size={15} aria-hidden="true" /></span><span>Help &amp; Support</span></button>
            <button class="tn-menu-item danger" role="menuitem" onclick={signOut}><span class="tn-ic"><Lock size={15} aria-hidden="true" /></span><span>Sign out</span></button>
          </div>
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
    height: 54px;
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
    border-radius: 9px;
    background: transparent;
    color: var(--sec);
    font-size: 13.5px;
    font-weight: 500;
    cursor: pointer;
    white-space: nowrap;
    transition: background 0.12s, color 0.12s;
  }
  .tn-top:hover { background: var(--hover); color: var(--text); }
  .tn-top.active { background: var(--purple-bg, var(--hover)); color: var(--purple-soft, var(--purple)); }
  .tn-top:focus-visible { outline: 2px solid var(--purple); outline-offset: 1px; }
  .tn-top :global(.tn-caret) { transition: transform 0.18s ease; opacity: 0.6; }
  .tn-top :global(.tn-caret.up) { transform: rotate(180deg); }

  .tn-menu {
    position: absolute;
    top: calc(100% + 8px);
    left: 0;
    min-width: 230px;
    padding: 7px;
    background: var(--raised, #17171b);
    border: 1px solid var(--border);
    border-radius: 14px;
    box-shadow: 0 4px 14px rgba(0, 0, 0, 0.14), 0 18px 44px rgba(0, 0, 0, 0.42);
    z-index: 100;
    transform-origin: top left;
    animation: tn-menu-in 0.16s cubic-bezier(0.16, 1, 0.3, 1);
  }
  @keyframes tn-menu-in {
    from { opacity: 0; transform: translateY(-7px) scale(0.97); }
    to { opacity: 1; transform: translateY(0) scale(1); }
  }
  .tn-menu.mega { display: grid; grid-template-columns: 208px 216px; gap: 4px; min-width: 0; }
  .tn-menu.mega .tn-col + .tn-col { border-left: 1px solid var(--border); padding-left: 5px; }

  .tn-col { display: flex; flex-direction: column; gap: 1px; min-width: 0; }
  .tn-sec-label { padding: 8px 10px 5px; font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.06em; color: var(--dim); }

  .tn-menu-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 6px 9px;
    border: none;
    border-radius: 10px;
    background: transparent;
    color: var(--sec);
    font-size: 13px;
    text-align: left;
    cursor: pointer;
    width: 100%;
    transition: background 0.11s, color 0.11s;
  }
  .tn-menu-item:hover { background: var(--hover); color: var(--text); }
  .tn-menu-item.active { color: var(--purple-soft, var(--purple)); }
  .tn-menu-item:focus-visible { outline: 2px solid var(--purple); outline-offset: -2px; }
  .tn-menu-item.danger:hover { color: var(--red); }

  .tn-ic {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    border-radius: 8px;
    background: var(--hover);
    flex-shrink: 0;
    transition: background 0.11s;
  }
  .tn-ic :global(svg) { color: var(--dim); transition: color 0.11s; }
  .tn-menu-item:hover .tn-ic :global(svg) { color: var(--text); }
  .tn-menu-item.active .tn-ic { background: var(--purple-bg, var(--hover)); }
  .tn-menu-item.active .tn-ic :global(svg) { color: var(--purple-soft, var(--purple)); }
  .tn-menu-item.danger:hover .tn-ic :global(svg) { color: var(--red); }

  .tn-right { display: flex; align-items: center; gap: 4px; margin-left: auto; }
  .tn-icon {
    display: inline-flex;
    padding: 7px;
    border: none;
    border-radius: 9px;
    background: transparent;
    color: var(--sec);
    cursor: pointer;
    transition: background 0.12s, color 0.12s;
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
  .tn-user-menu { left: auto; right: 0; min-width: 210px; }
  .tn-user-name { padding: 6px 10px 9px; font-size: 12px; color: var(--dim); border-bottom: 1px solid var(--border); margin-bottom: 4px; }

  @media (max-width: 900px) {
    .tn-top span { display: none; }
    .tn-brand { display: none; }
    .tn-menu.mega { grid-template-columns: 1fr; }
    .tn-menu.mega .tn-col + .tn-col { border-left: none; padding-left: 0; border-top: 1px solid var(--border); padding-top: 4px; margin-top: 2px; }
  }
</style>
