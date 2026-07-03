<script>
  import {
    currentRoute, currentCollectionSlug, navigate, searchOpen, collectionsList,
  } from '$lib/stores.js';
  import { Search, FileText, PenSquare, Upload, ClipboardList, Download, Command } from 'lucide-svelte';

  let route = $derived($currentRoute);
  let coll = $derived($currentCollectionSlug);
  let allColls = $derived($collectionsList || []);

  const HIDDEN = ['node-builder', 'setup'];

  function singular(name) {
    const n = String(name || '');
    if (/ies$/i.test(n)) return n.replace(/ies$/i, 'y');
    if (/[^s]s$/i.test(n)) return n.replace(/s$/i, '');
    return n;
  }

  function collLabel() {
    const c = allColls.find((x) => x.slug === coll);
    const n = c ? c.name || c.label || c.slug : null;
    return n ? `New ${singular(n)}` : 'New item';
  }

  let actions = $derived.by(() => {
    switch (route) {
      case 'pages':
        return [
          { label: 'New page', icon: FileText, run: () => navigate('page-new') },
          { label: 'Import HTML', icon: Download, run: () => navigate('page-import') },
        ];
      case 'collection-items':
        return [{ label: collLabel(), icon: PenSquare, emit: 'item:new' }];
      case 'media':
        return [{ label: 'Upload', icon: Upload, emit: 'media:upload' }];
      case 'forms-list':
      case 'forms':
        return [{ label: 'New form', icon: ClipboardList, emit: 'form:new' }];
      default:
        return [];
    }
  });

  let show = $derived(!HIDDEN.includes(route) && actions.length > 0);

  function act(a) {
    if (a.emit) window.dispatchEvent(new CustomEvent('outpost:quick-action', { detail: a.emit }));
    else a.run?.();
  }
</script>

{#if show}
  <div class="fb" role="toolbar" aria-label="Quick actions">
    {#each actions as a, i (a.label)}
      {#if i === 0}
        {@const PrimaryIcon = a.icon}
        <button class="fb-primary" onclick={() => act(a)}>
          <PrimaryIcon size={16} aria-hidden="true" />
          <span>{a.label}</span>
        </button>
      {:else}
        <button class="fb-ghost" onclick={() => act(a)} title={a.label}>
          <a.icon size={16} aria-hidden="true" />
          <span class="fb-ghost-label">{a.label}</span>
        </button>
      {/if}
    {/each}

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

  .fb-ghost {
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
  .fb-ghost:hover { background: var(--hover); color: var(--text); }
  .fb-ghost:focus-visible { outline: 2px solid var(--purple); outline-offset: 1px; }

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
