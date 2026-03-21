<script>
  import { onMount, onDestroy } from 'svelte';
  import { content as contentApi } from '$lib/api.js';
  import { addToast } from '$lib/stores.js';

  // ── API state ────────────────────────────────────────────
  let schema = $state(null);
  let syntaxGroups = $state([]);
  let loading = $state(true);

  // ── Navigation state ─────────────────────────────────────
  let activeType = $state(null); // 'collections' | 'pages' | 'globals' | 'syntax'
  let selectedItem = $state(null);
  let selectedSyntaxGroup = $state(null);

  // ── Data state ────────────────────────────────────────────
  let loopTemplate = $state('');
  let dataShape = $state(null);
  let dataLoading = $state(false);
  let dataError = $state(false);
  let copied = $state({});

  // ── CodeMirror ────────────────────────────────────────────
  let editorContainer = $state(null);
  let editorView = $state(null);

  // ── Derived ──────────────────────────────────────────────
  let collections = $derived(schema?.data?.collections ?? []);
  let pages = $derived(schema?.data?.pages ?? []);

  let navMode = $derived(
    (activeType === 'collections' || activeType === 'pages') ? 'items' : 'top'
  );
  let currentItems = $derived(
    activeType === 'collections' ? collections :
    activeType === 'pages' ? pages : []
  );
  let backLabel = $derived(
    activeType === 'collections' ? 'Collections' :
    activeType === 'pages' ? 'Pages' : ''
  );

  // ── Data-shape driven field lists ─────────────────────────
  // Schema type lookup for the selected item's fields (keyed by field.name for data-shape matching)
  let schemaTypeMap = $derived.by(() => {
    if (!selectedItem) return {};
    return Object.fromEntries((selectedItem.fields || []).map(f => [f.name, f.type]));
  });

  // Dynamic globals from API
  let globals = $derived(schema?.data?.globals ?? []);

  // Collection custom fields: keyed from dataShape.fields when loaded, else from schema
  let col2Fields = $derived.by(() => {
    if (activeType !== 'collections' || !selectedItem) return [];
    if (dataShape?.fields) {
      return Object.entries(dataShape.fields).map(([key, val]) => ({
        key,
        type: schemaTypeMap[key] ?? (val && typeof val === 'object' ? 'object' : 'text'),
      }));
    }
    return (selectedItem.fields || []).map(f => ({ key: fieldKey(f), type: f.type }));
  });

  // Collection built-in props: from top-level dataShape keys (not fields/terms), else fallback
  let col2BuiltIns = $derived.by(() => {
    if (activeType !== 'collections') return [];
    if (dataShape) {
      return Object.entries(dataShape)
        .filter(([k]) => k !== 'fields' && k !== 'terms')
        .map(([key, val]) => ({ key, type: val === null ? 'null' : typeof val }));
    }
    return [
      { key: 'slug', type: 'URL-safe identifier' },
      { key: 'url', type: 'Full URL path' },
      { key: 'created_at', type: 'Creation timestamp' },
      { key: 'published_at', type: 'Publish timestamp' },
    ];
  });

  // Page fields: always use schema-derived fields (parsed from theme templates)
  // Page fields use data-outpost attributes
  let col2PageFields = $derived.by(() => {
    if (activeType !== 'pages' || !selectedItem) return [];
    return (selectedItem.fields || []).map(f => {
      const key = fieldKey(f);
      const typeAttr = f.type === 'richtext' ? ' data-type="richtext"' : f.type === 'image' ? ' data-type="image"' : '';
      const tag = f.type === 'image' ? 'img' : (f.type === 'richtext' ? 'div' : 'span');
      return { key, type: f.type, snip: `<${tag} data-outpost="${key}"${typeAttr} />` };
    });
  });

  // Page globals: from schema endpoint (extractAllTemplateReferences)
  let col2PageGlobals = $derived.by(() => {
    if (activeType !== 'pages' || !selectedItem) return [];
    return (selectedItem.globals || []).map(g => {
      const typeAttr = g.type === 'richtext' ? ' data-type="richtext"' : g.type === 'image' ? ' data-type="image"' : '';
      const tag = g.type === 'image' ? 'img' : (g.type === 'richtext' ? 'div' : 'span');
      return {
        key: g.name, type: g.type,
        snip: `<${tag} data-outpost="${g.name}" data-scope="global"${typeAttr} />`,
      };
    });
  });

  // Page collections: from schema endpoint
  let col2PageCollections = $derived(selectedItem?.collections ?? []);
  let col2PageSingles = $derived(selectedItem?.singles ?? []);
  let col2PageGalleries = $derived(selectedItem?.galleries ?? []);
  let col2PageRepeaters = $derived(selectedItem?.repeaters ?? []);
  let col2PageMenus = $derived(selectedItem?.menus ?? []);
  let col2PageFolders = $derived(selectedItem?.taxonomies ?? []);

  // Page built-in props: top-level dataShape keys (not fields) when loaded
  // Page built-ins also use data-outpost attributes
  let col2PageBuiltIns = $derived.by(() => {
    if (activeType !== 'pages' || !selectedItem || !dataShape) return [];
    return Object.entries(dataShape)
      .filter(([k]) => k !== 'fields')
      .map(([key, val]) => ({ key, type: val === null ? 'null' : typeof val, snip: `<span data-outpost="${key}" />` }));
  });

  // Label property keys for a folder: from actual label data, else fallback
  function getLabelProps(folderSlug) {
    const labels = dataShape?.terms?.[folderSlug];
    return labels?.length > 0 ? Object.keys(labels[0]) : ['name', 'slug', 'id'];
  }

  // Snippet for a collection item field
  function itemFieldSnippet(key, type) {
    const typeAttr = type === 'richtext' ? ' data-type="richtext"' : type === 'image' ? ' data-type="image"' : '';
    const tag = type === 'image' ? 'img' : (type === 'richtext' ? 'div' : 'span');
    return `<${tag} data-outpost="${key}"${typeAttr} />`;
  }

  // ── Lifecycle ─────────────────────────────────────────────
  onMount(async () => {
    try {
      const [schemaResult, syntaxResult] = await Promise.all([
        contentApi.schema(),
        contentApi.syntax(),
      ]);
      schema = schemaResult;
      syntaxGroups = syntaxResult.data ?? [];
      if (syntaxGroups.length > 0) selectedSyntaxGroup = syntaxGroups[0].label;
    } catch (e) {
      addToast('Failed to load schema', 'error');
    } finally {
      loading = false;
    }
  });

  onDestroy(() => editorView?.destroy());

  // ── CodeMirror: init when container mounts, destroy when unmounts ──
  $effect(() => {
    if (editorContainer) {
      if (!editorView) initEditor(loopTemplate);
    } else {
      editorView?.destroy();
      editorView = null;
    }
  });

  // ── Navigation ───────────────────────────────────────────
  function setType(type) {
    activeType = type;
    selectedItem = null;
    loopTemplate = '';
    dataShape = null;
    dataError = false;
  }

  function goBack() {
    activeType = null;
    selectedItem = null;
    loopTemplate = '';
    dataShape = null;
    dataError = false;
  }

  let dataLoadTimer;

  async function selectItem(item) {
    selectedItem = item;
    const tpl = generateTemplate(item);
    loopTemplate = tpl;
    dataShape = null;
    dataError = false;
    dataLoading = true;
    if (editorView) updateEditor(tpl);
    clearTimeout(dataLoadTimer);
    dataLoadTimer = setTimeout(() => loadDataShape(item), 150);
  }

  // ── Template generation ───────────────────────────────────
  function fieldKey(field) {
    return field.label || field.name;
  }

  function fieldSnippet(field) {
    const key = fieldKey(field);
    const typeAttr = field.type === 'richtext' ? ' data-type="richtext"' : field.type === 'image' ? ' data-type="image"' : '';
    const tag = field.type === 'image' ? 'img' : (field.type === 'richtext' ? 'div' : 'span');
    return `<${tag} data-outpost="${key}"${typeAttr} />`;
  }

  function generateTemplate(item) {
    if (activeType === 'collections') {
      const slug = item.slug;
      const fields = item.fields.slice(0, 3);
      const lines = [`<outpost-each collection="${slug}">`];
      if (fields.length > 0) {
        for (const f of fields) lines.push('  ' + fieldSnippet(f));
      } else {
        lines.push('  <span data-outpost="title" />');
        lines.push('  <a data-outpost="url"><span data-outpost="title" /></a>');
      }
      lines.push('</outpost-each>');
      return lines.join('\n');
    }
    if (activeType === 'pages') {
      const lines = [`<!-- Template references for ${item.path || '/'} -->`];
      // Fields
      for (const f of (item.fields || []).slice(0, 3)) {
        const key = fieldKey(f);
        const typeAttr = f.type === 'richtext' ? ' data-type="richtext"' : f.type === 'image' ? ' data-type="image"' : '';
        const tag = f.type === 'image' ? 'img' : (f.type === 'richtext' ? 'div' : 'span');
        lines.push(`<${tag} data-outpost="${key}"${typeAttr} />`);
      }
      // Globals sample
      const gl = item.globals || [];
      if (gl.length > 0) {
        lines.push('');
        lines.push('<!-- Globals -->');
        for (const g of gl.slice(0, 3)) {
          const typeAttr = g.type === 'image' ? ' data-type="image"' : g.type === 'richtext' ? ' data-type="richtext"' : '';
          const tag = g.type === 'image' ? 'img' : (g.type === 'richtext' ? 'div' : 'span');
          lines.push(`<${tag} data-outpost="${g.name}" data-scope="global"${typeAttr} />`);
        }
        if (gl.length > 3) lines.push(`<!-- ... and ${gl.length - 3} more globals -->`);
      }
      // Collections
      for (const c of (item.collections || [])) {
        lines.push('');
        lines.push(`<outpost-each collection="${c.slug}"${c.options ? ' ' + c.options : ''}>`);
        lines.push('  <span data-outpost="title" />');
        lines.push('</outpost-each>');
      }
      // Singles
      for (const s of (item.singles || [])) {
        lines.push('');
        lines.push(`<outpost-single collection="${s.slug}">`);
        lines.push('  <span data-outpost="title" />');
        lines.push('</outpost-single>');
      }
      // Galleries
      for (const g of (item.galleries || [])) {
        lines.push('');
        lines.push(`<outpost-each gallery="${g}">`);
        lines.push('  <img data-outpost="url" data-type="image" />');
        lines.push('</outpost-each>');
      }
      // Repeaters
      for (const r of (item.repeaters || [])) {
        lines.push('');
        lines.push(`<outpost-each repeater="${r}">`);
        lines.push('  <span data-outpost="field" />');
        lines.push('</outpost-each>');
      }
      // Menus
      for (const m of (item.menus || [])) {
        lines.push('');
        lines.push(`<outpost-menu name="${m}">`);
        lines.push('  <a data-outpost="url"><span data-outpost="label" /></a>');
        lines.push('</outpost-menu>');
      }
      // Folders
      for (const t of (item.folders || item.taxonomies || [])) {
        lines.push('');
        lines.push(`<outpost-each folder="${t}">`);
        lines.push('  <span data-outpost="name" />');
        lines.push('</outpost-each>');
      }
      return lines.join('\n');
    }
    if (activeType === 'globals') {
      const lines = ['<!-- Available in every template -->'];
      for (const g of globals) {
        const typeAttr = g.type === 'richtext' ? ' data-type="richtext"' : g.type === 'image' ? ' data-type="image"' : '';
        const tag = g.type === 'image' ? 'img' : (g.type === 'richtext' ? 'div' : 'span');
        lines.push(`<${tag} data-outpost="${g.name}" data-scope="global"${typeAttr} />`);
      }
      if (lines.length === 1) lines.push('<span data-outpost="field_name" data-scope="global" />');
      return lines.join('\n');
    }
    return '';
  }

  // ── Data shape loading ────────────────────────────────────
  async function loadDataShape(item) {
    try {
      if (activeType === 'collections') {
        const r = await contentApi.items(item.slug, { limit: 1 });
        const raw = r.data?.[0] ?? null;
        if (raw && item.url_pattern) {
          raw.url = item.url_pattern.replace('{slug}', raw.slug);
        }
        dataShape = raw;
      } else if (activeType === 'pages') {
        // Build composite: template-referenced fields + globals + collection samples
        const shape = {};

        // Page fields — only keep template-referenced fields (exclude stale DB entries)
        const templateFieldNames = new Set((item.fields || []).map(f => f.name));
        const r = await contentApi.page(item.path);
        const allFields = r.data?.fields ?? {};
        const filteredFields = {};
        for (const [k, v] of Object.entries(allFields)) {
          if (templateFieldNames.has(k)) filteredFields[k] = v;
        }
        if (Object.keys(filteredFields).length > 0) shape.fields = filteredFields;

        // Globals — fetch actual values, filter to template-referenced ones
        if (item.globals?.length) {
          try {
            const gr = await contentApi.globals();
            const globalVals = gr.data ?? {};
            const refNames = new Set(item.globals.map(g => g.name));
            shape.globals = {};
            for (const [k, v] of Object.entries(globalVals)) {
              if (refNames.has(k)) shape.globals['@' + k] = v || `(${item.globals.find(g => g.name === k)?.type ?? 'text'})`;
            }
            // Add any referenced globals not in DB yet
            for (const g of item.globals) {
              if (!('@' + g.name in shape.globals)) shape.globals['@' + g.name] = `(${g.type})`;
            }
          } catch {
            shape.globals = {};
            for (const g of item.globals) shape.globals['@' + g.name] = `(${g.type})`;
          }
        }

        // Sample items from referenced collections + singles
        const collSlugs = [...new Set([
          ...(item.collections || []).map(c => c.slug),
          ...(item.singles || []).map(s => s.slug),
        ])];
        for (const slug of collSlugs) {
          try {
            const cr = await contentApi.items(slug, { limit: 1 });
            const sample = cr.data?.[0] ?? null;
            if (sample) shape['collection.' + slug] = sample;
          } catch {}
        }

        dataShape = Object.keys(shape).length > 0 ? shape : null;
      }
      if (!dataShape) dataError = true;
    } catch {
      dataShape = null;
      dataError = true;
    } finally {
      dataLoading = false;
    }
  }

  async function refreshData() {
    if (!selectedItem) return;
    dataShape = null;
    dataError = false;
    dataLoading = true;
    await loadDataShape(selectedItem);
  }

  // ── Copy ─────────────────────────────────────────────────
  async function copy(text, key) {
    try {
      await navigator.clipboard.writeText(text);
      copied = { ...copied, [key]: true };
      setTimeout(() => { copied = { ...copied, [key]: false }; }, 2000);
    } catch {}
  }

  // ── CodeMirror setup ─────────────────────────────────────
  async function initEditor(content) {
    if (!editorContainer || editorView) return;

    const [cm, cmState, cmView, cmLang, cmTheme] = await Promise.all([
      import('codemirror'),
      import('@codemirror/state'),
      import('@codemirror/view'),
      import('@codemirror/language'),
      import('@codemirror/theme-one-dark'),
    ]);

    // Minimal Liquid stream language for syntax highlighting
    const liquidLang = cmLang.StreamLanguage.define({
      token(stream) {
        if (stream.match(/\{#/)) {
          while (!stream.eol() && !stream.match('#}', false)) stream.next();
          stream.match('#}');
          return 'comment';
        }
        if (stream.match(/\{%-?/)) {
          while (!stream.eol() && !stream.match(/-?%\}/, false)) stream.next();
          stream.match(/-?%\}/);
          return 'keyword';
        }
        if (stream.match(/\{\{-?/)) {
          while (!stream.eol() && !stream.match(/-?\}\}/, false)) stream.next();
          stream.match(/-?\}\}/);
          return 'string';
        }
        stream.next();
        return null;
      },
    });

    const liquidTheme = cmView.EditorView.theme({
      '&': { background: '#1E1C1A', color: '#F5F3EF', fontSize: '13px', fontFamily: 'SF Mono, SFMono-Regular, Cascadia Code, Consolas, monospace', height: '100%' },
      '.cm-scroller': { overflow: 'auto' },
      '.cm-content': { padding: '16px 20px', lineHeight: '1.7', caretColor: '#F5F3EF' },
      '.cm-gutters': { background: '#1E1C1A', borderRight: '1px solid rgba(255,255,255,0.06)', color: '#4a4744', minWidth: '40px' },
      '.cm-lineNumbers .cm-gutterElement': { padding: '0 12px 0 8px' },
      '&.cm-focused': { outline: 'none' },
      '&.cm-focused .cm-cursor': { borderLeftColor: '#F5F3EF' },
      '.cm-activeLine': { background: 'rgba(255,255,255,0.025)' },
      '.cm-activeLineGutter': { background: 'rgba(255,255,255,0.025)' },
      '.cm-selectionBackground': { background: 'rgba(125,155,138,0.3) !important' },
    }, true);

    const state = cmState.EditorState.create({
      doc: content,
      extensions: [
        cm.basicSetup,
        cmTheme.oneDark,
        liquidTheme,
        liquidLang,
        cmView.EditorView.editable.of(false),
      ],
    });

    if (!editorContainer) return;
    editorView = new cmView.EditorView({ state, parent: editorContainer });

    if (editorView.state.doc.toString() !== loopTemplate) updateEditor(loopTemplate);
  }

  function updateEditor(content) {
    if (!editorView) return;
    editorView.dispatch({
      changes: { from: 0, to: editorView.state.doc.length, insert: content },
    });
  }

  // ── JSON syntax highlight ─────────────────────────────────
  function highlightJSON(json) {
    return json
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"([^"]+)"(\s*:)/g, '<span class="jk">"$1"</span>$2')
      .replace(/:\s*"([^"]*)"/g, ': <span class="js">"$1"</span>')
      .replace(/:\s*(-?\d+(?:\.\d+)?)/g, ': <span class="jn">$1</span>')
      .replace(/:\s*(true|false|null)/g, ': <span class="jb">$1</span>');
  }


</script>

<div class="tr-root">

  <!-- ── Column 1: Navigator (220px) ──────────────────────── -->
  <aside class="tr-col1">
    <div class="tr-col1-nav">
      {#if loading}
        <div class="tr-loading"><div class="spinner"></div></div>
      {:else if navMode === 'top'}
        {#each [['collections','Collections'],['pages','Pages'],['globals','Globals'],['syntax','Syntax']] as [type, label]}
          <button
            class="tr-nav-row"
            class:active={activeType === type}
            onclick={() => setType(type)}
          >
            <span>{label}</span>
            <svg class="tr-chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 18l6-6-6-6"/></svg>
          </button>
        {/each}
      {:else}
        <button class="tr-nav-back" onclick={goBack}>
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M15 18l-6-6 6-6"/></svg>
          {backLabel}
        </button>
        {#if currentItems.length === 0}
          <p class="tr-nav-empty">{activeType === 'collections' ? 'No collections yet' : 'No pages yet'}</p>
        {:else}
          {#each currentItems as item}
            <button
              class="tr-nav-item"
              class:active={selectedItem?.slug === item.slug || selectedItem?.path === item.path}
              onclick={() => selectItem(item)}
            >{item.name || item.title || item.path}</button>
          {/each}
        {/if}
      {/if}
    </div>
  </aside>

  <!-- ── Column 2: Fields Panel (300px) ───────────────────── -->
  <aside class="tr-col2">

    {#if activeType === 'collections' && selectedItem}
      {@const coll = selectedItem}
      <div class="tr-fields-head">
        <h2 class="tr-fields-title">{coll.name}</h2>
        <span class="tr-fields-sub">COLLECTION · {col2Fields.length} FIELD{col2Fields.length === 1 ? '' : 'S'}</span>
      </div>
      <div class="tr-fields-list">
        {#each col2Fields as {key, type}}
          {@const snip = itemFieldSnippet(key, type)}
          <button class="tr-field-row" onclick={() => copy(snip, 'f-' + key)} title="Copy snippet">
            <div class="tr-field-info">
              <span class="tr-field-name">{key}</span>
              <span class="tr-field-type">{type}</span>
            </div>
            <code class="tr-field-snip">{snip}</code>
          </button>
        {/each}

        {#if col2BuiltIns.length > 0}
          <div class="tr-sep-label">BUILT-IN</div>
          {#each col2BuiltIns as {key, type}}
            {@const snip = `<span data-outpost="${key}" />`}
            <button class="tr-field-row" onclick={() => copy(snip, 'b-' + key)} title={type}>
              <div class="tr-field-info">
                <span class="tr-field-name">{key}</span>
                <span class="tr-field-type">{type}</span>
              </div>
              <code class="tr-field-snip">data-outpost="{key}"</code>
            </button>
          {/each}
        {/if}

        {#if coll.taxonomies?.length > 0}
          <div class="tr-sep-label">FOLDERS</div>
          {#each coll.taxonomies as tax}
            {@const folderSnip = `<outpost-each folder="${tax.slug}">\n  <span data-outpost="name" />\n</outpost-each>`}
            <button class="tr-field-row" onclick={() => copy(folderSnip, 't-' + tax.slug)} title="Copy folder loop">
              <div class="tr-field-info">
                <span class="tr-field-name">{tax.name}</span>
                <span class="tr-field-type">folder</span>
              </div>
              <code class="tr-field-snip">folder="{tax.slug}"</code>
            </button>
            <div class="tr-term-props">
              {#each getLabelProps(tax.slug) as prop}
                <button class="tr-field-row tr-term-prop-row" onclick={() => copy(`<span data-outpost="${prop}" />`, 'lp-' + prop)} title="Copy data-outpost={prop}">
                  <div class="tr-field-info">
                    <span class="tr-field-name">{prop}</span>
                    <span class="tr-field-type">--</span>
                  </div>
                  <code class="tr-field-snip">data-outpost="{prop}"</code>
                </button>
              {/each}
            </div>
          {/each}
        {/if}
      </div>

    {:else if activeType === 'pages' && selectedItem}
      {@const page = selectedItem}
      {@const refCount = col2PageFields.length + col2PageGlobals.length + col2PageCollections.length + col2PageSingles.length + col2PageGalleries.length + col2PageRepeaters.length + col2PageMenus.length + col2PageFolders.length}
      <div class="tr-fields-head">
        <h2 class="tr-fields-title">{page.title || page.path}</h2>
        <span class="tr-fields-sub">PAGE · {refCount} REFERENCE{refCount === 1 ? '' : 'S'}</span>
      </div>
      <div class="tr-fields-list">
        {#if col2PageFields.length > 0}
          <div class="tr-sep-label">FIELDS</div>
          {#each col2PageFields as {key, type, snip}}
            <button class="tr-field-row" onclick={() => copy(snip, 'pf-' + key)}>
              <div class="tr-field-info">
                <span class="tr-field-name">{key}</span>
                <span class="tr-field-type">{type}</span>
              </div>
              <code class="tr-field-snip">{snip}</code>
            </button>
          {/each}
        {/if}

        {#if col2PageGlobals.length > 0}
          <div class="tr-sep-label">GLOBALS</div>
          {#each col2PageGlobals as {key, type, snip}}
            <button class="tr-field-row" onclick={() => copy(snip, 'pg-' + key)}>
              <div class="tr-field-info">
                <span class="tr-field-name">@{key}</span>
                <span class="tr-field-type">{type}</span>
              </div>
              <code class="tr-field-snip">{snip}</code>
            </button>
          {/each}
        {/if}

        {#if col2PageCollections.length > 0}
          <div class="tr-sep-label">COLLECTIONS</div>
          {#each col2PageCollections as c}
            {@const snip = `<outpost-each collection="${c.slug}"${c.options ? ' ' + c.options : ''}>`}
            <button class="tr-field-row" onclick={() => copy(snip, 'pc-' + c.slug)} title="Copy collection loop">
              <div class="tr-field-info">
                <span class="tr-field-name">{c.slug}</span>
                <span class="tr-field-type">{c.options || 'collection'}</span>
              </div>
              <code class="tr-field-snip">collection="{c.slug}"</code>
            </button>
          {/each}
        {/if}

        {#if col2PageSingles.length > 0}
          <div class="tr-sep-label">SINGLES</div>
          {#each col2PageSingles as s}
            {@const snip = `<outpost-single collection="${s.slug}">`}
            <button class="tr-field-row" onclick={() => copy(snip, 'ps-' + s.slug)} title="Copy single block">
              <div class="tr-field-info">
                <span class="tr-field-name">{s.slug}</span>
                <span class="tr-field-type">single</span>
              </div>
              <code class="tr-field-snip">collection="{s.slug}"</code>
            </button>
          {/each}
        {/if}

        {#if col2PageGalleries.length > 0}
          <div class="tr-sep-label">GALLERIES</div>
          {#each col2PageGalleries as g}
            {@const snip = `<outpost-each gallery="${g}">`}
            <button class="tr-field-row" onclick={() => copy(snip, 'pgal-' + g)} title="Copy gallery loop">
              <div class="tr-field-info">
                <span class="tr-field-name">{g}</span>
                <span class="tr-field-type">gallery</span>
              </div>
              <code class="tr-field-snip">gallery="{g}"</code>
            </button>
          {/each}
        {/if}

        {#if col2PageRepeaters.length > 0}
          <div class="tr-sep-label">REPEATERS</div>
          {#each col2PageRepeaters as r}
            {@const snip = `<outpost-each repeater="${r}">`}
            <button class="tr-field-row" onclick={() => copy(snip, 'pr-' + r)} title="Copy repeater loop">
              <div class="tr-field-info">
                <span class="tr-field-name">{r}</span>
                <span class="tr-field-type">repeater</span>
              </div>
              <code class="tr-field-snip">repeater="{r}"</code>
            </button>
          {/each}
        {/if}

        {#if col2PageMenus.length > 0}
          <div class="tr-sep-label">MENUS</div>
          {#each col2PageMenus as m}
            {@const snip = `<outpost-menu name="${m}">`}
            <button class="tr-field-row" onclick={() => copy(snip, 'pm-' + m)} title="Copy menu loop">
              <div class="tr-field-info">
                <span class="tr-field-name">{m}</span>
                <span class="tr-field-type">menu</span>
              </div>
              <code class="tr-field-snip">menu name="{m}"</code>
            </button>
          {/each}
        {/if}

        {#if col2PageFolders.length > 0}
          <div class="tr-sep-label">FOLDERS</div>
          {#each col2PageFolders as t}
            {@const snip = `<outpost-each folder="${t}">`}
            <button class="tr-field-row" onclick={() => copy(snip, 'pt-' + t)} title="Copy folder loop">
              <div class="tr-field-info">
                <span class="tr-field-name">{t}</span>
                <span class="tr-field-type">folder</span>
              </div>
              <code class="tr-field-snip">folder="{t}"</code>
            </button>
          {/each}
        {/if}

        {#if col2PageBuiltIns.length > 0}
          <div class="tr-sep-label">BUILT-IN</div>
          {#each col2PageBuiltIns as {key, type, snip}}
            <button class="tr-field-row" onclick={() => copy(snip, 'pb-' + key)}>
              <div class="tr-field-info">
                <span class="tr-field-name">{key}</span>
                <span class="tr-field-type">{type}</span>
              </div>
              <code class="tr-field-snip">{snip}</code>
            </button>
          {/each}
        {/if}

        {#if refCount === 0 && col2PageBuiltIns.length === 0}
          <p class="tr-fields-empty">No references found in this template.</p>
        {/if}
      </div>

    {:else if activeType === 'globals'}
      <div class="tr-fields-head">
        <h2 class="tr-fields-title">Globals</h2>
        <span class="tr-fields-sub">{globals.length} FIELD{globals.length === 1 ? '' : 'S'} · ALL TEMPLATES</span>
      </div>
      <div class="tr-fields-list">
        {#if globals.length > 0}
          {#each globals as g}
            {@const typeAttr = g.type === 'richtext' ? ' data-type="richtext"' : g.type === 'image' ? ' data-type="image"' : ''}
            {@const tag = g.type === 'image' ? 'img' : (g.type === 'richtext' ? 'div' : 'span')}
            {@const snip = `<${tag} data-outpost="${g.name}" data-scope="global"${typeAttr} />`}
            <button class="tr-field-row" onclick={() => copy(snip, 'g-' + g.name)} title="Copy snippet">
              <div class="tr-field-info">
                <span class="tr-field-name">@{g.name}</span>
                <span class="tr-field-type">{g.type}</span>
              </div>
              <code class="tr-field-snip">{snip}</code>
            </button>
          {/each}
        {:else}
          <p class="tr-fields-empty">No global fields yet.</p>
        {/if}
        <div class="tr-sep-label">META</div>
        {#each [
          { name: 'meta.title', snip: '<outpost-meta title="Default" />' },
          { name: 'meta.description', snip: '<outpost-meta description="Default" />' },
        ] as m}
          <button class="tr-field-row" onclick={() => copy(m.snip, 'g-' + m.name)}>
            <div class="tr-field-info">
              <span class="tr-field-name">{m.name}</span>
              <span class="tr-field-type">meta</span>
            </div>
            <code class="tr-field-snip">{m.snip}</code>
          </button>
        {/each}
        <div class="tr-sep-label">HOW IT WORKS</div>
        <p class="tr-globals-note">
          Add fields to the <code>__global__</code> page in Pages. Any field added there is available in every template via <code>data-outpost="field_name" data-scope="global"</code>.
        </p>
      </div>

    {:else if activeType === 'syntax'}
      <div class="tr-fields-head">
        <h2 class="tr-fields-title">Syntax</h2>
        <span class="tr-fields-sub">TAG REFERENCE</span>
      </div>
      <div class="tr-fields-list">
        {#each syntaxGroups as group}
          <button
            class="tr-nav-item"
            class:active={selectedSyntaxGroup === group.label}
            onclick={() => selectedSyntaxGroup = group.label}
          >{group.label}</button>
        {/each}
      </div>

    {:else}
      <div class="tr-col2-empty">← Select a content type</div>
    {/if}

  </aside>

  <!-- ── Column 3: Output (flex:1) ────────────────────────── -->
  <main class="tr-col3">

    {#if activeType === 'syntax'}
      <!-- Syntax cheat sheet — full height, white, scrollable -->
      <div class="tr-syntax-sheet">
        {#each syntaxGroups.filter(g => !selectedSyntaxGroup || g.label === selectedSyntaxGroup) as group}
          <section class="tr-syntax-section">
            <h4 class="tr-syntax-heading">{group.label}</h4>
            {#each group.rows as row}
              {@const sxKey = 'sx-' + row.syntax}
              <div class="tr-syntax-row">
                <div class="tr-syntax-code-block">
                  <code>{row.syntax}</code>
                  <button class="tr-code-copy-btn" onclick={() => copy(row.syntax, sxKey)} title="Copy">
                    {#if copied[sxKey]}
                      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                    {:else}
                      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>
                    {/if}
                  </button>
                </div>
                <p class="tr-syntax-desc">{row.description}</p>
              </div>
            {/each}
          </section>
        {/each}
      </div>

    {:else if selectedItem || activeType === 'globals'}
      <!-- Two stacked dark panels -->
      <div class="tr-panels">

        <!-- TOP: Syntax Preview (read-only) -->
        <div class="tr-panel tr-panel-loop">
          <div class="tr-panel-bar">
            <span class="tr-panel-label">SYNTAX PREVIEW</span>
            <div class="tr-panel-actions">
              <button class="tr-panel-btn" onclick={() => copy(loopTemplate, 'tpl')}>
                {#if copied.tpl}
                  <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                  Copied
                {:else}
                  Copy
                {/if}
              </button>
            </div>
          </div>
          <div class="tr-editor-wrap" bind:this={editorContainer}></div>
        </div>

        <!-- BOTTOM: Data Shape -->
        <div class="tr-panel tr-panel-data tr-panel-divider">
          <div class="tr-panel-bar">
            <span class="tr-panel-label">DATA SHAPE</span>
            <div class="tr-panel-actions">
              {#if activeType !== 'globals'}
                <button class="tr-panel-btn tr-panel-btn-icon" onclick={refreshData} title="Refresh">
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 11-2.12-9.36L23 10"/></svg>
                </button>
              {/if}
              {#if dataShape}
                <button class="tr-panel-btn" onclick={() => copy(JSON.stringify(dataShape, null, 2), 'json')}>
                  {#if copied.json}
                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                    Copied
                  {:else}
                    Copy
                  {/if}
                </button>
              {/if}
            </div>
          </div>
          <div class="tr-data-body">
            {#if dataLoading}
              <div class="tr-shimmer">
                <div class="tr-shimmer-line" style="width:70%"></div>
                <div class="tr-shimmer-line" style="width:52%;margin-left:18px"></div>
                <div class="tr-shimmer-line" style="width:64%;margin-left:18px"></div>
                <div class="tr-shimmer-line" style="width:38%;margin-left:18px"></div>
                <div class="tr-shimmer-line" style="width:10%"></div>
              </div>
            {:else if dataShape}
              <pre class="tr-json">{@html highlightJSON(JSON.stringify(dataShape, null, 2))}</pre>
            {:else if dataError}
              <p class="tr-data-msg">Could not load live data</p>
            {:else if activeType === 'globals'}
              <p class="tr-data-msg">Global fields are listed in the sidebar</p>
            {:else}
              <p class="tr-data-msg">No items in this collection yet</p>
            {/if}
          </div>
        </div>

      </div>

    {:else}
      <div class="tr-col3-empty">
        {#if !activeType}Select a content type to get started{:else}Select an item from the list{/if}
      </div>
    {/if}

  </main>

</div>

<style>
  /* ── Root / Layout ───────────────────────────────────────── */
  .tr-root {
    --tr-hover: var(--bg-hover);
    --tr-active: var(--bg-active);

    display: flex;
    height: calc(100vh - 60px);
    overflow: hidden;
    margin: calc(-1 * var(--space-2xl));
    margin-top: calc(-1 * var(--space-xl));
    background: var(--bg-primary);
    font-family: var(--font-sans);
    font-size: 15px;
    color: var(--text);
    line-height: 1.6;
  }

  /* ── Column 1: Navigator ────────────────────────────────── */
  .tr-col1 {
    width: 220px;
    flex-shrink: 0;
    border-right: 1px solid var(--border-light);
    display: flex;
    flex-direction: column;
    overflow: hidden;
    background: var(--bg-primary);
  }

  .tr-col1-nav {
    flex: 1;
    overflow-y: auto;
    min-height: 0;
  }

  .tr-loading {
    display: flex;
    justify-content: center;
    padding: 32px;
  }

  .tr-nav-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    width: 100%;
    height: 44px;
    padding: 0 16px;
    background: none;
    border: none;
    border-bottom: 1px solid var(--border-light);
    font-family: var(--font-sans);
    font-size: 15px;
    color: var(--text);
    cursor: pointer;
    text-align: left;
    transition: background var(--transition-fast);
  }
  .tr-nav-row:hover { background: var(--tr-hover); }
  .tr-nav-row.active { background: var(--forest-light); color: var(--forest); font-weight: 500; }
  .tr-nav-row.active .tr-chevron { stroke: var(--forest); opacity: 0.7; }
  .tr-chevron { color: var(--text-light); flex-shrink: 0; }

  .tr-nav-back {
    display: flex;
    align-items: center;
    gap: 6px;
    width: 100%;
    height: 40px;
    padding: 0 16px;
    background: none;
    border: none;
    border-bottom: 1px solid var(--border-light);
    font-family: var(--font-sans);
    font-size: 13px;
    color: var(--text-muted);
    cursor: pointer;
    text-align: left;
    transition: color var(--transition-fast);
  }
  .tr-nav-back:hover { color: var(--text); }

  .tr-nav-item {
    display: block;
    width: 100%;
    height: 40px;
    line-height: 40px;
    padding: 0 16px;
    background: none;
    border: none;
    font-family: var(--font-sans);
    font-size: 14px;
    color: var(--text);
    cursor: pointer;
    text-align: left;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    transition: background var(--transition-fast);
  }
  .tr-nav-item:hover { background: var(--tr-hover); }
  .tr-nav-item.active { background: var(--forest-light); color: var(--forest); font-weight: 500; }

  .tr-nav-empty {
    padding: 12px 16px;
    font-size: 13px;
    color: var(--text-muted);
    font-style: italic;
    margin: 0;
  }

  /* ── Column 2: Fields Panel ─────────────────────────────── */
  .tr-col2 {
    width: 300px;
    flex-shrink: 0;
    border-right: 1px solid var(--border-light);
    display: flex;
    flex-direction: column;
    overflow: hidden;
    background: var(--bg-primary);
  }

  .tr-col2-empty {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
    color: var(--text-light);
    padding: 24px;
    text-align: center;
  }

  .tr-fields-head {
    padding: 20px 16px 14px;
    border-bottom: 1px solid var(--border-light);
    flex-shrink: 0;
  }

  .tr-fields-title {
    font-family: var(--font-serif);
    font-size: 18px;
    font-weight: 600;
    color: var(--text);
    margin: 0 0 4px;
    line-height: 1.2;
  }

  .tr-fields-sub {
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--text-muted);
  }

  .tr-fields-list {
    flex: 1;
    overflow-y: auto;
  }

  .tr-fields-empty {
    padding: 16px;
    font-size: 13px;
    color: var(--text-muted);
    font-style: italic;
    margin: 0;
  }

  .tr-field-row {
    display: flex;
    align-items: center;
    width: 100%;
    min-height: 52px;
    padding: 0 16px;
    gap: 10px;
    background: none;
    border: none;
    border-bottom: 1px solid var(--border-light);
    cursor: pointer;
    text-align: left;
    font-family: var(--font-sans);
    transition: background var(--transition-fast);
  }
  .tr-field-row:hover { background: var(--tr-hover); }
  .tr-field-row:last-child { border-bottom: none; }

  .tr-term-props { background: var(--tr-active); }
  .tr-term-prop-row { padding-left: 20px; opacity: 0.75; }
  .tr-term-prop-row:hover { opacity: 1; background: var(--tr-active); }

  .tr-field-info {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
  }

  .tr-field-name {
    font-size: 14px;
    font-weight: 500;
    color: var(--text);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .tr-field-type {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--text-muted);
  }

  .tr-field-snip {
    font-family: var(--font-mono);
    font-size: 11px;
    color: var(--text-secondary);
    white-space: nowrap;
    flex-shrink: 0;
    max-width: 115px;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .tr-sep-label {
    padding: 10px 16px 6px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--text-light);
    background: var(--bg-primary);
    border-bottom: 1px solid var(--border-light);
  }

  .tr-globals-note {
    padding: 12px 16px;
    font-size: 13px;
    color: var(--text-muted);
    line-height: 1.6;
    margin: 0;
  }
  .tr-globals-note code {
    font-family: var(--font-mono);
    font-size: 12px;
    color: var(--text-secondary);
    background: var(--tr-hover);
    padding: 1px 5px;
    border-radius: 4px;
  }

  /* ── Column 3: Output ───────────────────────────────────── */
  .tr-col3 {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    background: var(--bg-primary);
  }

  .tr-col3-empty {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
    color: var(--text-light);
    text-align: center;
  }

  /* Two stacked dark panels */
  .tr-panels {
    flex: 1;
    display: flex;
    flex-direction: column;
    overflow: hidden;
  }

  .tr-panel {
    display: flex;
    flex-direction: column;
    overflow: hidden;
    min-height: 0;
    background: var(--code-bg);
  }

  /* Flex weight for each panel */
  .tr-panel-loop     { flex: 1.2; }
  .tr-panel-data     { flex: 0.9; }

  .tr-panel-divider {
    border-top: 1px solid rgba(255, 255, 255, 0.07);
  }

  .tr-panel-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    height: 40px;
    padding: 0 20px;
    flex-shrink: 0;
    border-bottom: 1px solid rgba(255, 255, 255, 0.07);
  }

  .tr-panel-label {
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--code-comment);
  }

  .tr-panel-actions {
    display: flex;
    align-items: center;
    gap: 2px;
  }

  .tr-panel-btn {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 10px;
    background: none;
    border: none;
    border-radius: 5px;
    font-family: var(--font-sans);
    font-size: 12px;
    color: var(--code-comment);
    cursor: pointer;
    transition: background var(--transition-fast), color var(--transition-fast);
  }
  .tr-panel-btn:hover { background: rgba(255,255,255,0.07); color: var(--code-text); }
  .tr-panel-btn:disabled { opacity: 0.5; cursor: default; }

  .tr-panel-btn-icon { padding: 4px 8px; }

  /* CodeMirror container */
  .tr-editor-wrap {
    flex: 1;
    overflow: hidden;
    min-height: 0;
  }
  .tr-editor-wrap :global(.cm-editor) { height: 100%; }
  .tr-editor-wrap :global(.cm-scroller) { overflow: auto; }

  /* Data body (shared by rendered + data panels) */
  .tr-data-body {
    flex: 1;
    overflow-y: auto;
    min-height: 0;
  }

  .tr-data-msg {
    padding: 20px;
    font-size: 13px;
    color: var(--code-comment);
    font-style: italic;
    margin: 0;
    text-align: center;
  }

  /* JSON pre */
  .tr-json {
    margin: 0;
    padding: 16px 20px;
    font-family: var(--font-mono);
    font-size: 12px;
    line-height: 1.75;
    color: var(--code-text);
    white-space: pre;
  }
  .tr-json :global(.jk) { color: var(--code-key); }
  .tr-json :global(.js) { color: var(--code-string); }
  .tr-json :global(.jn) { color: var(--code-value); }
  .tr-json :global(.jb) { color: var(--code-comment); }

  /* Shimmer loading */
  .tr-shimmer {
    padding: 20px;
    display: flex;
    flex-direction: column;
    gap: 10px;
  }
  .tr-shimmer-line {
    height: 11px;
    border-radius: 4px;
    background: rgba(255,255,255,0.07);
    animation: tr-pulse 1.6s ease-in-out infinite;
  }
  @keyframes tr-pulse {
    0%, 100% { opacity: 0.4; }
    50% { opacity: 0.9; }
  }

  /* ── Syntax Cheat Sheet ─────────────────────────────────── */
  .tr-syntax-sheet {
    flex: 1;
    overflow-y: auto;
    padding: 28px 32px;
    background: var(--bg-primary);
  }

  .tr-syntax-section {
    margin-bottom: 36px;
  }

  .tr-syntax-heading {
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.07em;
    color: var(--text-muted);
    margin: 0 0 14px;
  }

  .tr-syntax-row {
    margin-bottom: 12px;
  }

  .tr-syntax-code-block {
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: var(--bg-tertiary);
    border-radius: var(--radius-sm);
    padding: 10px 14px;
    gap: 8px;
    margin-bottom: 5px;
  }
  .tr-syntax-code-block code {
    font-family: var(--font-mono);
    font-size: 12px;
    color: var(--text);
    flex: 1;
  }

  .tr-code-copy-btn {
    flex-shrink: 0;
    background: none;
    border: none;
    cursor: pointer;
    color: var(--text-muted);
    padding: 2px;
    border-radius: 4px;
    display: flex;
    align-items: center;
    transition: color var(--transition-fast);
  }
  .tr-code-copy-btn:hover { color: var(--text); }

  .tr-syntax-desc {
    font-size: 13px;
    color: var(--text-muted);
    margin: 0 2px;
    line-height: 1.5;
  }

  @media (max-width: 768px) {
    .tr-root {
      flex-direction: column;
      height: calc(100vh - 60px - var(--mobile-nav-height) - env(safe-area-inset-bottom, 0px));
      overflow-y: auto;
    }

    .tr-col1 {
      width: 100%;
      border-right: none;
      border-bottom: 1px solid var(--border-light);
      overflow: visible;
      max-height: none;
    }

    .tr-col1-nav {
      overflow: visible;
    }

    .tr-col2 {
      width: 100%;
      border-right: none;
      border-bottom: 1px solid var(--border-light);
      overflow: visible;
      max-height: none;
    }

    .tr-col3 {
      width: 100%;
      overflow: visible;
      min-height: 300px;
    }
  }
</style>
