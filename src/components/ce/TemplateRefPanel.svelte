<script>
  import { onMount } from 'svelte';
  import { content as contentApi } from '$lib/api.js';
  import { addToast } from '$lib/stores.js';

  let { visible = false, onInsert = () => {}, onClose = () => {} } = $props();

  let schema = $state(null);
  let syntaxGroups = $state([]);
  let loading = $state(true);

  let activeType = $state(null);
  let selectedItem = $state(null);
  let copied = $state({});

  let collections = $derived(schema?.data?.collections ?? []);
  let pages = $derived(schema?.data?.pages ?? []);
  let globals = $derived(schema?.data?.globals ?? []);

  // Schema type lookup for selected item
  let schemaTypeMap = $derived.by(() => {
    if (!selectedItem) return {};
    return Object.fromEntries((selectedItem.fields || []).map(f => [f.name, f.type]));
  });

  onMount(async () => {
    try {
      const [schemaResult, syntaxResult] = await Promise.all([
        contentApi.schema(),
        contentApi.syntax(),
      ]);
      schema = schemaResult;
      syntaxGroups = syntaxResult.data ?? [];
    } catch (e) {
      addToast('Failed to load template data', 'error');
    } finally {
      loading = false;
    }
  });

  function goBack() {
    if (selectedItem) {
      selectedItem = null;
    } else {
      activeType = null;
    }
  }

  function selectCollection(coll) {
    selectedItem = coll;
  }

  function selectPage(page) {
    selectedItem = page;
  }

  function fieldKey(f) {
    return f.name || f.key || f.slug || '';
  }

  function snippetForField(key, type, prefix = '') {
    const full = prefix ? `${prefix}.${key}` : key;
    if (type === 'richtext') return `{{ ${full} | raw }}`;
    if (type === 'image') return `{{ ${full} | image }}`;
    if (type === 'link') return `{{ ${full} | link }}`;
    if (type === 'textarea') return `{{ ${full} | textarea }}`;
    return `{{ ${full} }}`;
  }

  function insertSnippet(text, key) {
    onInsert(text);
    copied = { ...copied, [key]: true };
    setTimeout(() => { copied = { ...copied, [key]: false }; }, 1500);
  }

  // Build the loop template for a collection
  function collectionLoopSnippet(slug, fields) {
    const inner = fields.slice(0, 3).map(f => {
      const k = fieldKey(f);
      return `  ${snippetForField(k, f.type, 'item')}`;
    }).join('\n');
    return `{% for item in collection.${slug} %}\n${inner || '  {{ item.title }}'}\n{% endfor %}`;
  }

  function singleSnippet(slug) {
    return `{% single item from collection.${slug} %}\n  {{ item.title }}\n{% else %}\n  Not found\n{% endsingle %}`;
  }

  // Derived fields for selected collection
  let collFields = $derived.by(() => {
    if (activeType !== 'collections' || !selectedItem) return [];
    return (selectedItem.fields || []).map(f => ({
      key: fieldKey(f),
      type: f.type,
      snip: snippetForField(fieldKey(f), f.type, 'item'),
    }));
  });

  // Derived fields for selected page
  let pageFields = $derived.by(() => {
    if (activeType !== 'pages' || !selectedItem) return [];
    return (selectedItem.fields || []).map(f => ({
      key: fieldKey(f),
      type: f.type,
      snip: snippetForField(fieldKey(f), f.type),
    }));
  });

  let pageGlobals = $derived.by(() => {
    if (activeType !== 'pages' || !selectedItem) return [];
    return (selectedItem.globals || []).map(g => ({
      key: g.name,
      type: g.type,
      snip: g.type === 'image' ? `{{ @${g.name} | image }}` : g.type === 'richtext' ? `{{ @${g.name} | raw }}` : `{{ @${g.name} }}`,
    }));
  });

  let pageCollections = $derived(selectedItem?.collections ?? []);
  let pageSingles = $derived(selectedItem?.singles ?? []);
  let pageMenus = $derived(selectedItem?.menus ?? []);
  let pageFolders = $derived(selectedItem?.taxonomies ?? []);

  let selectedSyntaxGroup = $state(null);
  let activeSyntaxItems = $derived.by(() => {
    if (!selectedSyntaxGroup) return [];
    const group = syntaxGroups.find(g => g.label === selectedSyntaxGroup);
    return group?.items ?? [];
  });
</script>

{#if visible}
  <div class="trp">
    <div class="trp-header">
      {#if activeType}
        <button class="trp-back" onclick={goBack}>
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M15 18l-6-6 6-6"/></svg>
        </button>
      {/if}
      <span class="trp-title">
        {#if activeType === 'collections' && selectedItem}
          {selectedItem.name}
        {:else if activeType === 'pages' && selectedItem}
          {selectedItem.title || selectedItem.path}
        {:else if activeType === 'syntax' && selectedSyntaxGroup}
          {selectedSyntaxGroup}
        {:else if activeType}
          {activeType === 'collections' ? 'Collections' : activeType === 'pages' ? 'Pages' : activeType === 'globals' ? 'Globals' : 'Syntax'}
        {:else}
          Template Ref
        {/if}
      </span>
      <button class="trp-close" onclick={onClose} title="Close">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>

    <div class="trp-body">
      {#if loading}
        <div class="trp-loading"><div class="spinner"></div></div>

      {:else if !activeType}
        <!-- Top-level categories -->
        {#each [['collections','Collections', collections.length],['pages','Pages', pages.length],['globals','Globals', globals.length],['syntax','Syntax', syntaxGroups.length]] as [type, label, count]}
          <button class="trp-cat-row" onclick={() => { activeType = type; selectedItem = null; selectedSyntaxGroup = null; }}>
            <span class="trp-cat-name">{label}</span>
            <span class="trp-cat-count">{count}</span>
            <svg class="trp-chevron" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 18l6-6-6-6"/></svg>
          </button>
        {/each}
        <div class="trp-hint">Click a snippet to insert at cursor</div>

      {:else if activeType === 'collections' && !selectedItem}
        {#each collections as coll}
          <button class="trp-cat-row" onclick={() => selectCollection(coll)}>
            <span class="trp-cat-name">{coll.name}</span>
            <span class="trp-cat-count">{(coll.fields || []).length}</span>
            <svg class="trp-chevron" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 18l6-6-6-6"/></svg>
          </button>
        {/each}

      {:else if activeType === 'collections' && selectedItem}
        <!-- Quick actions -->
        <div class="trp-section-label">QUICK INSERT</div>
        <button class="trp-snippet-btn" onclick={() => insertSnippet(collectionLoopSnippet(selectedItem.slug, selectedItem.fields || []), 'loop')}>
          <code>for item in collection.{selectedItem.slug}</code>
          {#if copied['loop']}<span class="trp-inserted">Inserted</span>{/if}
        </button>
        <button class="trp-snippet-btn" onclick={() => insertSnippet(singleSnippet(selectedItem.slug), 'single')}>
          <code>single item from collection.{selectedItem.slug}</code>
          {#if copied['single']}<span class="trp-inserted">Inserted</span>{/if}
        </button>

        {#if collFields.length > 0}
          <div class="trp-section-label">FIELDS</div>
          {#each collFields as {key, type, snip}}
            <button class="trp-field-row" onclick={() => insertSnippet(snip, 'cf-' + key)}>
              <span class="trp-field-name">{key}</span>
              <span class="trp-field-type">{type}</span>
              {#if copied['cf-' + key]}<span class="trp-inserted">Inserted</span>{/if}
            </button>
          {/each}
        {/if}

        <!-- Built-ins -->
        <div class="trp-section-label">BUILT-IN</div>
        {#each ['slug','url','created_at','published_at'] as key}
          {@const snip = '{{ item.' + key + ' }}'}
          <button class="trp-field-row" onclick={() => insertSnippet(snip, 'cb-' + key)}>
            <span class="trp-field-name">{key}</span>
            <span class="trp-field-type">built-in</span>
            {#if copied['cb-' + key]}<span class="trp-inserted">Inserted</span>{/if}
          </button>
        {/each}

        {#if selectedItem.taxonomies?.length > 0}
          <div class="trp-section-label">FOLDERS</div>
          {#each selectedItem.taxonomies as tax}
            {@const snip = '{% for label in folder.' + tax.slug + ' %}\n  {{ label.name }}\n{% endfor %}'}
            <button class="trp-field-row" onclick={() => insertSnippet(snip, 'ct-' + tax.slug)}>
              <span class="trp-field-name">{tax.name || tax.slug}</span>
              <span class="trp-field-type">folder</span>
              {#if copied['ct-' + tax.slug]}<span class="trp-inserted">Inserted</span>{/if}
            </button>
          {/each}
        {/if}

      {:else if activeType === 'pages' && !selectedItem}
        {#each pages as page}
          <button class="trp-cat-row" onclick={() => selectPage(page)}>
            <span class="trp-cat-name">{page.title || page.path}</span>
            <span class="trp-cat-count">{(page.fields || []).length}</span>
            <svg class="trp-chevron" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 18l6-6-6-6"/></svg>
          </button>
        {/each}

      {:else if activeType === 'pages' && selectedItem}
        {#if pageFields.length > 0}
          <div class="trp-section-label">FIELDS</div>
          {#each pageFields as {key, type, snip}}
            <button class="trp-field-row" onclick={() => insertSnippet(snip, 'pf-' + key)}>
              <span class="trp-field-name">{key}</span>
              <span class="trp-field-type">{type}</span>
              {#if copied['pf-' + key]}<span class="trp-inserted">Inserted</span>{/if}
            </button>
          {/each}
        {/if}

        {#if pageGlobals.length > 0}
          <div class="trp-section-label">GLOBALS</div>
          {#each pageGlobals as {key, type, snip}}
            <button class="trp-field-row" onclick={() => insertSnippet(snip, 'pg-' + key)}>
              <span class="trp-field-name">@{key}</span>
              <span class="trp-field-type">{type}</span>
              {#if copied['pg-' + key]}<span class="trp-inserted">Inserted</span>{/if}
            </button>
          {/each}
        {/if}

        {#if pageCollections.length > 0}
          <div class="trp-section-label">COLLECTIONS</div>
          {#each pageCollections as slug}
            {@const snip = '{% for item in collection.' + slug + ' %}\n  {{ item.title }}\n{% endfor %}'}
            <button class="trp-field-row" onclick={() => insertSnippet(snip, 'pc-' + slug)}>
              <span class="trp-field-name">collection.{slug}</span>
              <span class="trp-field-type">loop</span>
              {#if copied['pc-' + slug]}<span class="trp-inserted">Inserted</span>{/if}
            </button>
          {/each}
        {/if}

        {#if pageSingles.length > 0}
          <div class="trp-section-label">SINGLES</div>
          {#each pageSingles as slug}
            {@const snip = '{% single item from collection.' + slug + ' %}\n  {{ item.title }}\n{% endsingle %}'}
            <button class="trp-field-row" onclick={() => insertSnippet(snip, 'ps-' + slug)}>
              <span class="trp-field-name">collection.{slug}</span>
              <span class="trp-field-type">single</span>
              {#if copied['ps-' + slug]}<span class="trp-inserted">Inserted</span>{/if}
            </button>
          {/each}
        {/if}

        {#if pageMenus.length > 0}
          <div class="trp-section-label">MENUS</div>
          {#each pageMenus as slug}
            {@const snip = '{% for link in menu.' + slug + ' %}\n  <a href="{{ link.url }}">{{ link.label }}</a>\n{% endfor %}'}
            <button class="trp-field-row" onclick={() => insertSnippet(snip, 'pm-' + slug)}>
              <span class="trp-field-name">menu.{slug}</span>
              <span class="trp-field-type">menu</span>
              {#if copied['pm-' + slug]}<span class="trp-inserted">Inserted</span>{/if}
            </button>
          {/each}
        {/if}

        {#if pageFolders.length > 0}
          <div class="trp-section-label">FOLDERS</div>
          {#each pageFolders as slug}
            {@const snip = '{% for label in folder.' + slug + ' %}\n  {{ label.name }}\n{% endfor %}'}
            <button class="trp-field-row" onclick={() => insertSnippet(snip, 'pt-' + slug)}>
              <span class="trp-field-name">folder.{slug}</span>
              <span class="trp-field-type">folder</span>
              {#if copied['pt-' + slug]}<span class="trp-inserted">Inserted</span>{/if}
            </button>
          {/each}
        {/if}

      {:else if activeType === 'globals'}
        {#each globals as g}
          {@const snip = g.type === 'image' ? `{{ @${g.name} | image }}` : g.type === 'richtext' ? `{{ @${g.name} | raw }}` : `{{ @${g.name} }}`}
          <button class="trp-field-row" onclick={() => insertSnippet(snip, 'gl-' + g.name)}>
            <span class="trp-field-name">@{g.name}</span>
            <span class="trp-field-type">{g.type}</span>
            {#if copied['gl-' + g.name]}<span class="trp-inserted">Inserted</span>{/if}
          </button>
        {/each}
        {#if globals.length === 0}
          <p class="trp-empty">No globals found</p>
        {/if}

      {:else if activeType === 'syntax' && !selectedSyntaxGroup}
        {#each syntaxGroups as group}
          <button class="trp-cat-row" onclick={() => { selectedSyntaxGroup = group.label; }}>
            <span class="trp-cat-name">{group.label}</span>
            <span class="trp-cat-count">{(group.items || []).length}</span>
            <svg class="trp-chevron" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 18l6-6-6-6"/></svg>
          </button>
        {/each}

      {:else if activeType === 'syntax' && selectedSyntaxGroup}
        {#each activeSyntaxItems as item}
          <button class="trp-snippet-btn trp-syntax-item" onclick={() => insertSnippet(item.template, 'sx-' + item.label)}>
            <div class="trp-syntax-label">{item.label}</div>
            <code>{item.template}</code>
            {#if copied['sx-' + item.label]}<span class="trp-inserted">Inserted</span>{/if}
          </button>
        {/each}
      {/if}
    </div>
  </div>
{/if}

<style>
  .trp {
    width: 260px;
    flex-shrink: 0;
    display: flex;
    flex-direction: column;
    border-left: 1px solid var(--border-light);
    background: var(--bg-primary);
    height: 100%;
    overflow: hidden;
  }

  .trp-header {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 8px 10px;
    border-bottom: 1px solid var(--border-light);
    flex-shrink: 0;
  }

  .trp-title {
    flex: 1;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--text-secondary);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .trp-back, .trp-close {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 22px;
    height: 22px;
    padding: 0;
    background: none;
    border: none;
    border-radius: var(--radius-sm);
    color: var(--text-tertiary);
    cursor: pointer;
    flex-shrink: 0;
  }

  .trp-back:hover, .trp-close:hover {
    background: var(--bg-secondary);
    color: var(--text-primary);
  }

  .trp-body {
    flex: 1;
    overflow-y: auto;
    padding: 4px 0;
  }

  .trp-loading {
    display: flex;
    justify-content: center;
    padding: 32px;
  }

  /* Category rows */
  .trp-cat-row {
    display: flex;
    align-items: center;
    gap: 6px;
    width: 100%;
    padding: 8px 12px;
    background: none;
    border: none;
    cursor: pointer;
    text-align: left;
    color: var(--text-primary);
    font-size: 13px;
    transition: background 0.1s;
  }

  .trp-cat-row:hover {
    background: var(--bg-secondary);
  }

  .trp-cat-name {
    flex: 1;
  }

  .trp-cat-count {
    font-size: 11px;
    color: var(--text-tertiary);
    font-family: var(--font-mono);
  }

  .trp-chevron {
    color: var(--text-tertiary);
    flex-shrink: 0;
  }

  /* Section labels */
  .trp-section-label {
    font-size: 10px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--text-tertiary);
    padding: 12px 12px 4px;
  }

  /* Field rows */
  .trp-field-row {
    display: flex;
    align-items: center;
    gap: 6px;
    width: 100%;
    padding: 6px 12px;
    background: none;
    border: none;
    cursor: pointer;
    text-align: left;
    font-size: 12px;
    transition: background 0.1s;
    color: var(--text-primary);
  }

  .trp-field-row:hover {
    background: var(--bg-secondary);
  }

  .trp-field-name {
    flex: 1;
    font-family: var(--font-mono);
    font-size: 11px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .trp-field-type {
    font-size: 10px;
    color: var(--text-tertiary);
    flex-shrink: 0;
  }

  /* Snippet buttons (loop, single, syntax) */
  .trp-snippet-btn {
    display: block;
    width: 100%;
    padding: 8px 12px;
    background: none;
    border: none;
    cursor: pointer;
    text-align: left;
    transition: background 0.1s;
    position: relative;
    color: var(--text-primary);
  }

  .trp-snippet-btn:hover {
    background: var(--bg-secondary);
  }

  .trp-snippet-btn code {
    font-family: var(--font-mono);
    font-size: 11px;
    color: var(--text-secondary);
    line-height: 1.5;
    white-space: pre-wrap;
    word-break: break-all;
  }

  .trp-syntax-item {
    border-bottom: 1px solid var(--border-light);
  }

  .trp-syntax-label {
    font-size: 12px;
    font-weight: 500;
    margin-bottom: 4px;
    color: var(--text-primary);
  }

  .trp-inserted {
    position: absolute;
    right: 10px;
    top: 50%;
    transform: translateY(-50%);
    font-size: 10px;
    font-weight: 600;
    color: var(--color-success, #22c55e);
    pointer-events: none;
  }

  .trp-hint {
    font-size: 11px;
    color: var(--text-tertiary);
    padding: 12px;
    text-align: center;
    border-top: 1px solid var(--border-light);
    margin-top: 4px;
  }

  .trp-empty {
    font-size: 12px;
    color: var(--text-tertiary);
    padding: 16px 12px;
    text-align: center;
  }
</style>
