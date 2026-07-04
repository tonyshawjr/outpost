<script>
  import { onMount } from 'svelte';
  import { templates as templatesApi } from '$lib/api.js';
  import { navigate, addToast } from '$lib/stores.js';
  import { LayoutGrid, Code, FileText, Home, Newspaper, Mail, AlertTriangle, Layers } from 'lucide-svelte';

  let templates = $state([]);
  let theme = $state('');
  let loading = $state(true);

  const ICONS = {
    index: Home, home: Home, page: FileText, post: Newspaper, single: FileText,
    blog: Newspaper, archive: Layers, contact: Mail, '404': AlertTriangle, landing: LayoutGrid,
  };
  function iconFor(slug) { return ICONS[slug] || FileText; }

  onMount(async () => {
    try {
      const res = await templatesApi.list();
      templates = res.templates || [];
      theme = res.theme || '';
    } catch (e) { addToast(e?.message || 'Could not load templates', 'error'); }
    finally { loading = false; }
  });

  function edit(t) {
    if (!t.file) { addToast('No editable file found for this template', 'error'); return; }
    navigate('code-editor', { file: t.file });
  }
</script>

<div class="wrap">
  <header class="page-header">
    <div class="page-header-text">
      <h1 class="page-title">Templates</h1>
      <p class="page-subtitle">The layouts your {theme || 'active'} theme uses to render pages and collection items. Open one to edit its markup.</p>
    </div>
  </header>

  {#if loading}
    <p class="muted">Loading…</p>
  {:else if templates.length === 0}
    <div class="empty">
      <Layers size={26} aria-hidden="true" />
      <p>No templates found in the active theme.</p>
    </div>
  {:else}
    <div class="grid">
      {#each templates as t (t.slug)}
        {@const Icon = iconFor(t.slug)}
        <div class="card">
          <div class="card-top">
            <span class="icon"><Icon size={18} aria-hidden="true" /></span>
            <div class="titles">
              <span class="name">{t.name}</span>
              <span class="slug">{t.slug}</span>
            </div>
          </div>
          {#if t.description}<p class="desc">{t.description}</p>{/if}
          <div class="card-foot">
            {#if t.file}
              <span class="file">{t.file}</span>
              <button class="edit" onclick={() => edit(t)}>
                <Code size={14} aria-hidden="true" />
                <span>Edit</span>
              </button>
            {:else}
              <span class="file muted">No file</span>
            {/if}
          </div>
        </div>
      {/each}
    </div>
  {/if}
</div>

<style>
  .wrap { max-width: var(--content-width-wide, 1100px); margin: 0 auto; padding: 0 24px 80px; }
  .muted { color: var(--dim); }

  .empty { display: flex; flex-direction: column; align-items: center; gap: 12px; padding: 60px 0; color: var(--dim); text-align: center; }

  .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 14px; margin-top: 8px; }

  .card { display: flex; flex-direction: column; gap: 12px; padding: 16px; border: 1px solid var(--border); border-radius: 12px; background: var(--raised); }
  .card-top { display: flex; align-items: center; gap: 12px; }
  .icon { display: inline-flex; align-items: center; justify-content: center; width: 38px; height: 38px; border-radius: 9px; background: var(--hover); color: var(--sec); flex-shrink: 0; }
  .titles { display: flex; flex-direction: column; gap: 1px; min-width: 0; }
  .name { font-size: 14px; font-weight: 600; color: var(--text); }
  .slug { font-size: 11.5px; color: var(--dim); font-family: var(--font-mono, ui-monospace, monospace); }
  .desc { font-size: 12.5px; color: var(--dim); line-height: 1.5; margin: 0; }

  .card-foot { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-top: auto; padding-top: 4px; }
  .file { font-size: 11.5px; color: var(--sec); font-family: var(--font-mono, ui-monospace, monospace); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .edit { display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; border: 1px solid var(--border); border-radius: 8px; background: transparent; color: var(--text); font-size: 12.5px; font-weight: 600; cursor: pointer; flex-shrink: 0; }
  .edit:hover { background: var(--hover); border-color: var(--purple); }
  .edit:focus-visible { outline: 2px solid var(--purple); outline-offset: 1px; }
</style>
