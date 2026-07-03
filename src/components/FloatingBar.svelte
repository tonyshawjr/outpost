<script>
  import { currentRoute, navigate, searchOpen, currentCollectionSlug } from '$lib/stores.js';
  import { Search, Plus, FileText, PenSquare, Upload, Mail, Columns3, Box, Command } from 'lucide-svelte';

  let route = $derived($currentRoute);
  let coll = $derived($currentCollectionSlug);

  const HIDDEN = ['node-builder', 'setup'];

  let action = $derived.by(() => {
    switch (route) {
      case 'pages':
        return { label: 'New page', icon: FileText, run: () => navigate('node-builder') };
      case 'collection-items':
        return { label: 'New item', icon: PenSquare, run: () => navigate('collection-editor', { collectionSlug: coll || 'post' }) };
      case 'collections':
      case 'collection-schema':
        return { label: 'New collection', icon: Columns3, run: () => navigate('collections') };
      case 'media':
        return { label: 'Upload media', icon: Upload, run: () => navigate('media') };
      case 'newsletter':
        return { label: 'New broadcast', icon: Mail, run: () => navigate('newsletter') };
      case 'page-builder':
        return { label: 'Open builder', icon: Box, run: () => navigate('node-builder') };
      default:
        return null;
    }
  });

  let createOpen = $state(false);
  const createOptions = [
    { label: 'New page', icon: FileText, run: () => navigate('node-builder') },
    { label: 'New post', icon: PenSquare, run: () => navigate('collection-editor', { collectionSlug: 'post' }) },
    { label: 'Newsletter', icon: Mail, run: () => navigate('newsletter') },
  ];

  function runAction(fn) {
    createOpen = false;
    fn();
  }

  $effect(() => {
    if (!createOpen) return;
    function handler(e) { if (!e.target.closest('.fb')) createOpen = false; }
    setTimeout(() => document.addEventListener('click', handler), 0);
    return () => document.removeEventListener('click', handler);
  });
</script>

{#if !HIDDEN.includes(route)}
  <div class="fb" role="toolbar" aria-label="Quick actions">
    {#if action}
      <button class="fb-primary" onclick={() => runAction(action.run)}>
        <action.icon size={16} aria-hidden="true" />
        <span>{action.label}</span>
      </button>
    {:else}
      <div class="fb-create-wrap">
        <button class="fb-primary" onclick={() => (createOpen = !createOpen)} aria-expanded={createOpen}>
          <Plus size={16} aria-hidden="true" />
          <span>Create</span>
        </button>
        {#if createOpen}
          <div class="fb-menu" role="menu">
            {#each createOptions as o (o.label)}
              <button class="fb-menu-item" role="menuitem" onclick={() => runAction(o.run)}>
                <o.icon size={15} aria-hidden="true" />
                <span>{o.label}</span>
              </button>
            {/each}
          </div>
        {/if}
      </div>
    {/if}

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
    gap: 6px;
    padding: 6px;
    background: var(--raised, #17171b);
    border: 1px solid var(--border);
    border-radius: 999px;
    box-shadow: 0 10px 34px rgba(0, 0, 0, 0.4);
  }

  .fb-create-wrap { position: relative; }

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
  }
  .fb-primary:hover { background: var(--accent-hover, var(--purple)); }
  .fb-primary:focus-visible { outline: 2px solid var(--purple-soft, var(--purple)); outline-offset: 2px; }

  .fb-menu {
    position: absolute;
    bottom: calc(100% + 8px);
    left: 0;
    min-width: 180px;
    padding: 6px;
    background: var(--raised, #17171b);
    border: 1px solid var(--border);
    border-radius: 12px;
    box-shadow: 0 12px 34px rgba(0, 0, 0, 0.4);
    display: flex;
    flex-direction: column;
    gap: 1px;
  }
  .fb-menu-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px 10px;
    border: none;
    border-radius: 8px;
    background: transparent;
    color: var(--sec);
    font-size: 13px;
    text-align: left;
    cursor: pointer;
  }
  .fb-menu-item:hover { background: var(--hover); color: var(--text); }
  .fb-menu-item:focus-visible { outline: 2px solid var(--purple); outline-offset: -2px; }
  .fb-menu-item :global(svg) { color: var(--dim); flex-shrink: 0; }

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
  }
</style>
