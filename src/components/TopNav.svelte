<script>
  import { onMount } from 'svelte';
  import {
    currentRoute, currentCollectionSlug, navigate, user, searchOpen, darkMode,
    canManageSettings, canAccessCodeEditor, canBuildForms,
    canManageMembers, canManageChannels, isDeveloper, isAdmin, featureFlags,
    collectionsList, collectionGrants,
  } from '$lib/stores.js';
  import { auth, collections as collectionsApi, content as contentApi } from '$lib/api.js';
  import {
    Home, Globe, FileText, Layout, Sparkles, SwatchBook, Palette, ArrowLeftRight,
    LayoutGrid, Box, PenSquare, Mail, Calendar, GitBranch, MessageSquare, ClipboardList,
    Columns3, Database, FolderOpen, BarChart3, Rss, Workflow, Archive, Upload, Lock,
    Code, Search, Sun, Moon, Settings, Settings2, ChevronDown, LifeBuoy, Image, Utensils, Briefcase, Wrench,
    ArrowRight,
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

  let recentContent = $state([]);

  onMount(async () => {
    try {
      const data = await collectionsApi.list();
      collectionsList.set(data.collections || []);
    } catch (e) {}
    try {
      const rc = await contentApi.recent();
      recentContent = rc.results || [];
    } catch (e) {}
  });

  function openRecent(item) {
    navigate('collection-editor', { itemId: item.id, collectionSlug: item.collection_slug });
    openGroup = null;
  }

  let viewAllCollection = $derived(recentContent[0]?.collection_slug || contentCollections[0]?.slug || null);

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
      .map((c) => {
        const name = c.name || c.label || c.slug;
        return { label: name, slug: c.slug, icon: collIcon(c.slug), desc: c.description || `Manage your ${String(name).toLowerCase()}` };
      })
  );

  let groups = $derived([
    { label: 'Dashboard', icon: Home, route: 'dashboard' },
    {
      label: 'Site', icon: Globe, items: [
        { label: 'Pages', route: 'pages', icon: FileText, on: true, activeAlso: ['node-builder', 'page-new', 'page-import'], desc: 'Build and edit standalone pages' },
        { label: 'Navigation', route: 'navigation', icon: Layout, on: feat('navigation'), desc: 'Menus and links across your site' },
        { label: 'Globals', route: 'globals', icon: Sparkles, on: true, desc: 'Reusable content shared site-wide' },
        { label: 'Design', route: 'design', icon: SwatchBook, on: showSettings, desc: 'Theme, colors, and typography' },
        { label: 'Brand', route: 'brand', icon: Palette, on: showSettings, desc: 'Logo, favicon, and brand assets' },
        { label: 'Redirects', route: 'redirects', icon: ArrowLeftRight, on: showAdmin, desc: 'Forward old URLs to new ones' },
        { label: 'View site', route: 'view-site', icon: Globe, on: true, desc: 'Open your live site in a new tab' },
      ],
    },
    {
      label: 'Content', icon: PenSquare, mega: true, sections: [
        {
          title: 'Create & manage',
          items: [
            { label: 'Visual Builder', route: 'node-builder', icon: Box, on: showSettings || showDeveloper, desc: 'Design pages on a live canvas' },
            { label: 'Editorial AI', route: 'editorial-ai', icon: Sparkles, on: showAdmin, desc: 'Draft and refine content with AI' },
            { label: 'Newsletter', route: 'newsletter', icon: Mail, on: showAdmin, desc: 'Compose and send email campaigns' },
            { label: 'Calendar', route: 'calendar', icon: Calendar, on: showAdmin, desc: 'Schedule and plan your content' },
            { label: 'Releases', route: 'releases', icon: GitBranch, on: showAdmin, desc: 'Bundle changes and publish together' },
            { label: 'Review Links', route: 'review-tokens', icon: MessageSquare, on: showAdmin, desc: 'Share drafts for private feedback' },
            { label: 'Forms', route: 'forms-list', icon: ClipboardList, on: showFormBuilder, activeAlso: ['forms', 'form-builder', 'form-submissions'], desc: 'Build forms and collect responses' },
          ],
        },
        {
          title: 'Collections',
          items: [
            ...contentCollections.map((c) => ({
              label: c.label, icon: c.icon, on: feat('collections'), desc: c.desc,
              route: 'collection-items', collectionSlug: c.slug,
            })),
            { label: 'Manage collections', icon: Settings2, route: 'collections', on: showSettings || showDeveloper, activeAlso: ['collection-schema'], desc: 'Create and edit collection types' },
          ],
        },
      ],
    },
    {
      label: 'Data', icon: Database, items: [
        { label: 'Collections', route: 'collections', icon: Columns3, on: showSettings || showDeveloper, activeAlso: ['collection-schema'], desc: 'Define collection types and fields' },
        { label: 'Field Presets', route: 'field-presets', icon: Database, on: showSettings || showDeveloper, desc: 'Reusable field groups for schemas' },
        { label: 'Folders', route: 'folder-manager', icon: FolderOpen, on: showSettings || showDeveloper, desc: 'Organize your media into folders' },
        { label: 'Analytics', route: 'analytics', icon: BarChart3, on: showAdmin, activeAlso: ['analytics-events', 'analytics-goals', 'analytics-search', 'analytics-content', 'analytics-funnels'], desc: 'Traffic, events, and goals' },
        { label: 'Channels', route: 'channels', icon: Rss, on: showChannels, activeAlso: ['channel-builder'], desc: 'Pull in external feeds and APIs' },
        { label: 'Workflows', route: 'workflows', icon: Workflow, on: showAdmin, desc: 'Custom editorial approval stages' },
        { label: 'Backups', route: 'backups', icon: Archive, on: showAdmin, desc: 'Snapshot and restore your site' },
        { label: 'Code Editor', route: 'code-editor', icon: Code, on: showCode, desc: 'Edit theme templates and code' },
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

  let avatarUrl = $derived(currentUser?.avatar || currentUser?.avatar_url || '');

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
            <div class="tn-menu" class:mega={g.mega} class:has-recent={g.mega && recentContent.length} role="menu">
              {#each sectionsOf(g) as sec (sec.title)}
                <div class="tn-col">
                  {#if sec.title}<div class="tn-sec-label">{sec.title}</div>{/if}
                  {#each sec.items as it (it.collectionSlug || it.route)}
                    <button class="tn-menu-item" class:has-desc={it.desc} class:active={itemActive(it)} role="menuitem" onclick={() => go(it)}>
                      <span class="tn-ic"><it.icon size={17} aria-hidden="true" /></span>
                      <span class="tn-item-body">
                        <span class="tn-item-title">{it.label}</span>
                        {#if it.desc}<span class="tn-item-desc">{it.desc}</span>{/if}
                      </span>
                      {#if it.desc}<ArrowRight size={15} aria-hidden="true" class="tn-arrow" />{/if}
                    </button>
                  {/each}
                </div>
              {/each}

              {#if g.mega && recentContent.length}
                <div class="tn-recent">
                  <div class="tn-sec-label">Recent</div>
                  <div class="tn-recent-list">
                    {#each recentContent.slice(0, 4) as item (item.id)}
                      {@const RIcon = collIcon(item.collection_slug)}
                      <button class="tn-recent-item" onclick={() => openRecent(item)}>
                        {#if item.thumb}
                          <img class="tn-recent-thumb" src={item.thumb} alt="" loading="lazy" />
                        {:else}
                          <span class="tn-recent-thumb tn-recent-ph"><RIcon size={17} aria-hidden="true" /></span>
                        {/if}
                        <span class="tn-recent-body">
                          <span class="tn-recent-title">{item.title}</span>
                          <span class="tn-recent-meta">{item.collection_name}</span>
                        </span>
                      </button>
                    {/each}
                  </div>
                  {#if viewAllCollection}
                    <button class="tn-recent-all" onclick={() => go({ route: 'collection-items', collectionSlug: viewAllCollection })}>
                      <span>View all content</span>
                      <ArrowRight size={14} aria-hidden="true" />
                    </button>
                  {/if}
                </div>
              {/if}
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
      <button class="tn-avatar" class:has-img={avatarUrl} onclick={() => (userMenuOpen = !userMenuOpen)} aria-expanded={userMenuOpen} aria-label="Account menu">
        {#if avatarUrl}
          <img class="tn-avatar-img" src={avatarUrl} alt="" />
        {:else}
          {initials}
        {/if}
      </button>
      {#if userMenuOpen}
        <div class="tn-menu tn-user-menu" role="menu">
          <div class="tn-col">
            <div class="tn-user-name">{currentUser?.display_name || currentUser?.email || 'Account'}</div>
            <button class="tn-menu-item" role="menuitem" onclick={() => { navigate('user-profile', { userId: currentUser?.id }); userMenuOpen = false; }}><span class="tn-ic"><Settings size={16} aria-hidden="true" /></span><span>Profile</span></button>
            <button class="tn-menu-item" role="menuitem" onclick={() => { window.open('/outpost/docs/', '_blank', 'noopener'); userMenuOpen = false; }}><span class="tn-ic"><LifeBuoy size={16} aria-hidden="true" /></span><span>Help &amp; Support</span></button>
            <button class="tn-menu-item danger" role="menuitem" onclick={signOut}><span class="tn-ic"><Lock size={16} aria-hidden="true" /></span><span>Sign out</span></button>
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
    gap: 16px;
    height: 54px;
    padding: 0 16px;
    background: var(--raised);
    border-bottom: 1px solid var(--border);
    flex-shrink: 0;
  }
  .tn-brand {
    font-weight: 600;
    font-size: 15px;
    letter-spacing: -0.01em;
    color: var(--text);
    cursor: pointer;
    padding-right: 4px;
  }
  .tn-brand:focus-visible { outline: 2px solid var(--purple); outline-offset: 3px; border-radius: 4px; }

  .tn-nav { display: flex; align-items: center; gap: 1px; flex: 1; min-width: 0; }
  .tn-group { position: relative; }
  .tn-top {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 6px 10px;
    border: none;
    border-radius: 7px;
    background: transparent;
    color: var(--sec);
    font-size: 13px;
    font-weight: 500;
    letter-spacing: -0.005em;
    cursor: pointer;
    white-space: nowrap;
    transition: background 0.12s ease, color 0.12s ease;
  }
  .tn-top :global(svg) { color: var(--dim); transition: color 0.12s ease; }
  .tn-top:hover { color: var(--text); background: var(--hover); }
  .tn-top:hover :global(svg) { color: var(--sec); }
  .tn-top.active { color: var(--text); font-weight: 550; background: var(--hover); }
  .tn-top.active :global(svg) { color: var(--text); }
  .tn-top:focus-visible { outline: 2px solid var(--purple); outline-offset: 1px; }
  .tn-top :global(.tn-caret) { transition: transform 0.18s ease; color: var(--dim); }
  .tn-top :global(.tn-caret.up) { transform: rotate(180deg); }

  .tn-menu {
    position: absolute;
    top: calc(100% + 22px);
    left: 0;
    min-width: 300px;
    padding: 6px;
    background: var(--raised);
    border: 1px solid var(--border);
    border-radius: 12px;
    box-shadow: var(--shadow-lg);
    z-index: 100;
    transform-origin: top left;
    animation: tn-menu-in 0.16s cubic-bezier(0.16, 1, 0.3, 1);
  }
  @keyframes tn-menu-in {
    from { opacity: 0; transform: translateY(-6px) scale(0.985); }
    to { opacity: 1; transform: translateY(0) scale(1); }
  }
  .tn-menu.mega { display: grid; grid-template-columns: 300px 320px; gap: 0; min-width: 0; padding: 8px; }
  .tn-menu.mega.has-recent { grid-template-columns: 268px 288px 276px; gap: 0; padding: 8px; }

  .tn-col { display: flex; flex-direction: column; gap: 1px; min-width: 0; }
  .tn-menu.mega .tn-col + .tn-col {
    border-left: 1px solid var(--border);
    padding-left: 20px;
    margin-left: 20px;
  }
  .tn-sec-label {
    padding: 6px 10px 8px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--dim);
  }

  .tn-menu-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 8px 10px;
    border: none;
    border-radius: 8px;
    background: transparent;
    color: var(--sec);
    text-align: left;
    cursor: pointer;
    width: 100%;
    transition: background 0.12s ease;
  }
  .tn-menu-item.has-desc { align-items: flex-start; }
  .tn-menu-item:hover { background: var(--hover); }
  .tn-menu-item:focus-visible { outline: 2px solid var(--purple); outline-offset: -2px; }

  .tn-ic {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 18px;
    flex-shrink: 0;
  }
  .tn-menu-item.has-desc .tn-ic { margin-top: 2px; }
  .tn-ic :global(svg) { color: var(--dim); transition: color 0.12s ease; }
  .tn-menu-item:hover .tn-ic :global(svg) { color: var(--text); }
  .tn-menu-item.active .tn-ic :global(svg) { color: var(--purple); }
  .tn-menu-item.danger:hover .tn-ic :global(svg) { color: var(--red); }

  .tn-item-body { display: flex; flex-direction: column; gap: 2px; min-width: 0; flex: 1; }
  .tn-item-title { font-size: 13px; font-weight: 500; color: var(--text); line-height: 1.35; letter-spacing: -0.005em; }
  .tn-item-desc { font-size: 12px; font-weight: 400; color: var(--dim); line-height: 1.4; }
  .tn-menu-item:not(.has-desc) .tn-item-title,
  .tn-menu-item:not(.has-desc) > span:last-child { font-size: 13px; font-weight: 500; color: var(--sec); }
  .tn-menu-item:not(.has-desc):hover > span:last-child { color: var(--text); }
  .tn-menu-item.active .tn-item-title { color: var(--purple); }
  .tn-menu-item.danger:hover > span:last-child { color: var(--red); }

  .tn-menu-item :global(.tn-arrow) {
    align-self: center;
    color: var(--dim);
    opacity: 0;
    transform: translateX(-4px);
    transition: opacity 0.12s ease, transform 0.12s ease;
    flex-shrink: 0;
  }
  .tn-menu-item:hover :global(.tn-arrow) { opacity: 1; transform: translateX(0); }
  .tn-menu-item.active :global(.tn-arrow) { opacity: 1; color: var(--purple); transform: translateX(0); }

  .tn-recent {
    display: flex;
    flex-direction: column;
    min-width: 0;
    padding-left: 20px;
    margin-left: 20px;
    border-left: 1px solid var(--border);
  }
  .tn-recent-list { display: flex; flex-direction: column; gap: 1px; }
  .tn-recent-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 6px 8px;
    border: none;
    background: transparent;
    border-radius: 8px;
    cursor: pointer;
    text-align: left;
    width: 100%;
    transition: background 0.12s ease;
  }
  .tn-recent-item:hover { background: var(--hover); }
  .tn-recent-item:focus-visible { outline: 2px solid var(--purple); outline-offset: -2px; }
  .tn-recent-thumb {
    width: 38px;
    height: 38px;
    border-radius: 8px;
    object-fit: cover;
    flex-shrink: 0;
    background: var(--hover);
  }
  .tn-recent-ph { display: inline-flex; align-items: center; justify-content: center; }
  .tn-recent-ph :global(svg) { color: var(--dim); }
  .tn-recent-body { display: flex; flex-direction: column; gap: 1px; min-width: 0; }
  .tn-recent-title {
    font-size: 13px;
    font-weight: 500;
    color: var(--text);
    line-height: 1.35;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    letter-spacing: -0.005em;
  }
  .tn-recent-meta { font-size: 11px; color: var(--dim); }
  .tn-recent-all {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    margin-top: 6px;
    padding: 8px 8px;
    border: none;
    background: transparent;
    color: var(--purple-soft);
    font-size: 12px;
    font-weight: 500;
    cursor: pointer;
    border-radius: 8px;
  }
  .tn-recent-all :global(svg) { transition: transform 0.12s ease; }
  .tn-recent-all:hover :global(svg) { transform: translateX(3px); }
  .tn-recent-all:focus-visible { outline: 2px solid var(--purple); outline-offset: 1px; }

  @media (max-width: 1240px) {
    .tn-menu.mega.has-recent { grid-template-columns: 300px 320px; gap: 20px; padding: 8px; }
    .tn-recent { display: none; }
  }

  .tn-right { display: flex; align-items: center; gap: 2px; margin-left: auto; }
  .tn-icon {
    display: inline-flex;
    padding: 7px;
    border: none;
    border-radius: 8px;
    background: transparent;
    color: var(--sec);
    cursor: pointer;
    transition: background 0.12s ease, color 0.12s ease;
  }
  .tn-icon :global(svg) { color: var(--dim); transition: color 0.12s ease; }
  .tn-icon:hover { background: var(--hover); }
  .tn-icon:hover :global(svg) { color: var(--text); }
  .tn-icon.active :global(svg) { color: var(--purple); }
  .tn-icon:focus-visible { outline: 2px solid var(--purple); outline-offset: 1px; }

  .tn-user { position: relative; margin-left: 6px; display: inline-flex; align-items: center; }
  .tn-avatar {
    width: 30px;
    height: 30px;
    border-radius: 50%;
    border: none;
    background: var(--purple);
    color: #fff;
    font-size: 12.5px;
    font-weight: 600;
    cursor: pointer;
    transition: opacity 0.12s ease, box-shadow 0.12s ease;
    overflow: hidden;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0;
    box-shadow: 0 0 0 1px var(--border);
  }
  .tn-avatar.has-img { background: transparent; }
  .tn-avatar-img { width: 100%; height: 100%; object-fit: cover; border-radius: 50%; display: block; }
  .tn-avatar:hover { opacity: 0.9; }
  .tn-avatar:focus-visible { outline: 2px solid var(--purple); outline-offset: 2px; }
  .tn-user-menu { left: auto; right: 0; min-width: 216px; }
  .tn-user-menu .tn-menu-item { align-items: center; gap: 12px; }
  .tn-user-name {
    padding: 8px 10px 10px;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--dim);
    margin-bottom: 2px;
  }

  @media (max-width: 900px) {
    .tn-top span { display: none; }
    .tn-brand { display: none; }
    .tn-menu.mega { grid-template-columns: 1fr; }
    .tn-menu.mega .tn-col + .tn-col { margin-top: 4px; padding-top: 4px; }
  }
</style>
