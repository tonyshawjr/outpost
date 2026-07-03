<script>
  import {
    currentRoute, currentCollectionSlug, navigate, searchOpen,
    collectionsList, collectionGrants,
  } from '$lib/stores.js';
  import {
    Search, Plus, FileText, PenSquare, Upload, Mail, Columns3, ClipboardList,
    Download, Command,
  } from 'lucide-svelte';

  let route = $derived($currentRoute);
  let coll = $derived($currentCollectionSlug);
  let allColls = $derived($collectionsList || []);
  let grants = $derived($collectionGrants);

  const HIDDEN = ['node-builder', 'setup'];

  let actions = $derived.by(() => {
    switch (route) {
      case 'pages':
        return [
          { label: 'New page', icon: FileText, run: () => navigate('node-builder') },
          { label: 'Import HTML', icon: Download, run: () => navigate('page-import') },
        ];
      case 'collection-items':
        return [{ label: 'New item', icon: PenSquare, run: () => navigate('collection-editor', { collectionSlug: coll || 'post' }) }];
      case 'collections':
      case 'collection-schema':
        return [{ label: 'New collection', icon: Columns3, run: () => navigate('collections') }];
      case 'media':
        return [{ label: 'Upload media', icon: Upload, run: () => navigate('media') }];
      case 'newsletter':
        return [{ label: 'New broadcast', icon: Mail, run: () => navigate('newsletter') }];
      case 'forms-list':
      case 'forms':
        return [{ label: 'New form', icon: ClipboardList, run: () => navigate('form-builder') }];
      default:
        return [];
    }
  });

  let createOptions = $derived([
    { label: 'New page', icon: FileText, run: () => navigate('node-builder') },
    { label: 'Upload media', icon: Upload, run: () => navigate('media') },
    ...(grants == null ? allColls : allColls.filter((c) => grants.includes(c.id)))
      .filter((c) => c.slug !== 'pages')
      .map((c) => ({ label: `New ${c.name || c.label || c.slug}`, icon: PenSquare, run: () => navigate('collection-editor', { collectionSlug: c.slug }) })),
  ]);

  let createOpen = $state(false);
  function run(fn) { createOpen = false; fn(); }

  $effect(() => {
    if (!createOpen) return;
    function handler(e) { if (!e.target.closest('.fb')) createOpen = false; }
    function onKey(e) { if (e.key === 'Escape') createOpen = false; }
    setTimeout(() => document.addEventListener('click', handler), 0);
    document.addEventListener('keydown', onKey);
    return () => { document.removeEventListener('click', handler); document.removeEventListener('keydown', onKey); };
  });
</script>

{#if !HIDDEN.includes(route)}
  <div class="fb" role="toolbar" aria-label="Quick actions">
    {#if actions.length}
      {@const PrimaryIcon = actions[0].icon}
      <button class="fb-primary" onclick={() => run(actions[0].run)}>
        <PrimaryIcon size={16} aria-hidden="true" />
        <span>{actions[0].label}</span>
      </button>
      {#each actions.slice(1) as a (a.label)}
        <button class="fb-ghost" onclick={() => run(a.run)} title={a.label}>
          <a.icon size={16} aria-hidden="true" />
          <span class="fb-ghost-label">{a.label}</span>
        </button>
      {/each}
    {/if}

    <div class="fb-create-wrap">
      <button
        class:fb-primary={actions.length === 0}
        class:fb-plus={actions.length > 0}
        onclick={() => (createOpen = !createOpen)}
        aria-expanded={createOpen}
        aria-label="Create"
        title="Create"
      >
        <Plus size={16} aria-hidden="true" />
        {#if actions.length === 0}<span>Create</span>{/if}
      </button>
      {#if createOpen}
        <div class="fb-menu" role="menu">
          {#each createOptions as o (o.label)}
            <button class="fb-menu-item" role="menuitem" onclick={() => run(o.run)}>
              <span class="fb-ic"><o.icon size={15} aria-hidden="true" /></span>
              <span>{o.label}</span>
            </button>
          {/each}
        </div>
      {/if}
    </div>

    <span class="fb-divider" aria-hidden="true"></span>

    <button class="fb-icon" onclick={() => searchOpen.set(true)} aria-label="Search (Command K)" title="Search (⌘K)">
      <Search size={16} aria-hidden="true" />
      <kbd class="fb-kbd"><Command size={11} aria-hidden="true" />K</kbd>
    </button>
  </div>
{/if}

<style>
  .fb {
    position: fixed;
    left: 50%;
    bottom: 22px;
    transform: translateX(-50%);
    z-index: 90;
    display: flex;
    align-items: center;
    gap: 5px;
    padding: 6px;
    background: var(--raised, #17171b);
    border: 1px solid var(--border);
    border-radius: 999px;
    box-shadow: 0 10px 34px rgba(0, 0, 0, 0.4);
  }

  .fb-create-wrap { position: relative; display: flex; }

  .fb-primary {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 8px 16px;
    border: none;
    border-radius: 999px;
    background: var(--purple);
    color: #fff;
    font-size: 13.5px;
    font-weight: 600;
    cursor: pointer;
    transition: filter 0.12s;
  }
  .fb-primary:hover { filter: brightness(1.08); }
  .fb-primary:focus-visible { outline: 2px solid var(--purple-soft, var(--purple)); outline-offset: 2px; }

  .fb-ghost, .fb-plus {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 12px;
    border: none;
    border-radius: 999px;
    background: transparent;
    color: var(--sec);
    font-size: 13px;
    font-weight: 500;
    cursor: pointer;
    transition: background 0.12s, color 0.12s;
  }
  .fb-ghost:hover, .fb-plus:hover { background: var(--hover); color: var(--text); }
  .fb-ghost:focus-visible, .fb-plus:focus-visible { outline: 2px solid var(--purple); outline-offset: 1px; }
  .fb-plus { padding: 8px; }

  .fb-menu {
    position: absolute;
    bottom: calc(100% + 10px);
    left: 50%;
    transform: translateX(-50%);
    min-width: 210px;
    padding: 7px;
    background: var(--raised, #17171b);
    border: 1px solid var(--border);
    border-radius: 14px;
    box-shadow: 0 4px 14px rgba(0, 0, 0, 0.14), 0 18px 44px rgba(0, 0, 0, 0.42);
    display: flex;
    flex-direction: column;
    gap: 1px;
    animation: fb-menu-in 0.16s cubic-bezier(0.16, 1, 0.3, 1);
    transform-origin: bottom center;
  }
  @keyframes fb-menu-in {
    from { opacity: 0; transform: translateX(-50%) translateY(8px) scale(0.96); }
    to { opacity: 1; transform: translateX(-50%) translateY(0) scale(1); }
  }
  .fb-menu-item {
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
    transition: background 0.11s, color 0.11s;
  }
  .fb-menu-item:hover { background: var(--hover); color: var(--text); }
  .fb-menu-item:focus-visible { outline: 2px solid var(--purple); outline-offset: -2px; }
  .fb-ic {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    border-radius: 8px;
    background: var(--hover);
    flex-shrink: 0;
  }
  .fb-ic :global(svg) { color: var(--dim); }
  .fb-menu-item:hover .fb-ic :global(svg) { color: var(--text); }

  .fb-divider { width: 1px; height: 22px; background: var(--border); margin: 0 2px; }

  .fb-icon {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 12px 8px 11px;
    border: none;
    border-radius: 999px;
    background: transparent;
    color: var(--sec);
    cursor: pointer;
    transition: background 0.12s, color 0.12s;
  }
  .fb-icon:hover { background: var(--hover); color: var(--text); }
  .fb-icon:focus-visible { outline: 2px solid var(--purple); outline-offset: 1px; }
  .fb-kbd {
    display: inline-flex;
    align-items: center;
    gap: 1px;
    padding: 2px 5px;
    background: var(--hover);
    border-radius: 5px;
    font-size: 10.5px;
    color: var(--dim);
  }

  @media (max-width: 640px) {
    .fb-kbd { display: none; }
    .fb-ghost-label { display: none; }
  }
</style>
