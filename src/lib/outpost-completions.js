/**
 * Outpost template autocomplete source for CodeMirror 6
 * Supports both v1 Liquid syntax and v2 data-attribute syntax.
 */

let _ctx = { globals: [], collections: [] };

export function setOutpostContext(ctx) {
  _ctx = ctx || { globals: [], collections: [] };
}

/**
 * v2 data-attribute completions — triggered by typing `data-` in HTML.
 */
function v2DataAttrCompletions(context) {
  // Match data- prefix (possibly with partial attribute name)
  const word = context.matchBefore(/data-[\w-]*/);
  if (!word) return null;

  const { collections = [] } = _ctx;

  const options = [
    { label: 'data-outpost', type: 'keyword', detail: 'field binding', apply: 'data-outpost=""', boost: 10 },
    { label: 'data-type', type: 'keyword', detail: 'field type hint', boost: 9 },
    { label: 'data-scope', type: 'keyword', detail: 'scope (global)', apply: 'data-scope="global"', boost: 8 },
    { label: 'data-bind', type: 'keyword', detail: 'attribute binding', apply: 'data-bind=""', boost: 7 },
  ];

  // data-type values
  const types = ['richtext', 'image', 'link', 'textarea', 'toggle', 'select', 'number', 'date', 'color'];
  types.forEach(t => {
    options.push({ label: `data-type="${t}"`, type: 'keyword', detail: `field type: ${t}`, apply: `data-type="${t}"` });
  });

  return { from: word.from, options, filter: true };
}

/**
 * v2 custom element completions — triggered by typing `<outpost-` in HTML.
 */
function v2CustomElementCompletions(context) {
  const word = context.matchBefore(/<outpost-[\w-]*/);
  if (!word) return null;

  const { collections = [] } = _ctx;

  const elements = [
    { tag: 'outpost-each',       detail: 'collection/repeater loop',  snippet: `<outpost-each collection="">\\n  \\n</outpost-each>` },
    { tag: 'outpost-single',     detail: 'single item lookup',        snippet: `<outpost-single collection="">\\n  \\n</outpost-single>` },
    { tag: 'outpost-if',         detail: 'conditional block',         snippet: `<outpost-if field="">\\n  \\n</outpost-if>` },
    { tag: 'outpost-include',    detail: 'partial include',           snippet: `<outpost-include partial="">` },
    { tag: 'outpost-menu',       detail: 'navigation menu loop',     snippet: `<outpost-menu name="">\\n  \\n</outpost-menu>` },
    { tag: 'outpost-meta',       detail: 'meta title/description',   snippet: `<outpost-meta title="" description="">` },
    { tag: 'outpost-seo',        detail: 'SEO meta tags',            snippet: `<outpost-seo>` },
    { tag: 'outpost-pagination', detail: 'pagination controls',      snippet: `<outpost-pagination>\\n  \\n</outpost-pagination>` },
  ];

  const options = elements.map(e => ({
    label: `<${e.tag}>`,
    type: 'type',
    detail: e.detail,
    apply: e.snippet,
  }));

  // Add collection-specific loops
  collections.forEach(c => {
    options.push({
      label: `<outpost-each collection="${c.slug}">`,
      type: 'type',
      detail: c.name || c.slug,
      apply: `<outpost-each collection="${c.slug}">\\n  \\n</outpost-each>`,
    });
  });

  return { from: word.from, options, filter: true };
}

/**
 * v2 block comment completions — triggered by typing `<!-- outpost` in HTML.
 */
function v2BlockCommentCompletions(context) {
  const word = context.matchBefore(/<!--\s*outpost[\w:-]*/);
  if (!word) return null;

  const options = [
    { label: '<!-- outpost:blockname -->', type: 'keyword', detail: 'block group start', apply: '<!-- outpost:section -->' },
    { label: '<!-- /outpost:blockname -->', type: 'keyword', detail: 'block group end', apply: '<!-- /outpost:section -->' },
    { label: '<!-- outpost-settings: -->', type: 'keyword', detail: 'block settings', apply: '<!-- outpost-settings: field_name(type) -->' },
  ];

  return { from: word.from, options, filter: true };
}

/**
 * Completion source — registers via htmlLanguage.data
 * Provides v1 Liquid {{ }} / {% %} completions AND v2 data-attribute completions.
 */
export function outpostCompletionSource(context) {
  const { globals = [], collections = [] } = _ctx;

  const line = context.state.doc.lineAt(context.pos);
  const before = line.text.slice(0, context.pos - line.from);

  // ── v2: data- attribute completions ──
  if (/data-[\w-]*$/.test(before)) {
    return v2DataAttrCompletions(context);
  }

  // ── v2: <outpost- custom element completions ──
  if (/<outpost-[\w-]*$/.test(before)) {
    return v2CustomElementCompletions(context);
  }

  // ── v2: <!-- outpost block comment completions ──
  if (/<!--\s*outpost[\w:-]*$/.test(before)) {
    return v2BlockCommentCompletions(context);
  }

  // ── v1: Detect if inside {{ ... }} ──
  const lastOpen2  = before.lastIndexOf('{{');
  const lastClose2 = before.lastIndexOf('}}');
  const inDouble   = lastOpen2 >= 0 && lastOpen2 > lastClose2;

  // ── v1: Detect if inside {% ... %} ──
  const lastOpenPct  = before.lastIndexOf('{%');
  const lastClosePct = before.lastIndexOf('%}');
  const inBlock      = lastOpenPct >= 0 && lastOpenPct > lastClosePct;

  if (!inDouble && !inBlock) return null;

  if (inDouble) {
    const afterOpen  = before.slice(lastOpen2 + 2).trimStart();
    const word       = context.matchBefore(/[\w@.|]*/) || { from: context.pos, text: '' };

    let options = [];

    if (afterOpen.startsWith('@') || afterOpen === '') {
      // Global field completions
      globals.forEach(g => {
        options.push({
          label: `@${g.name}`,
          type: 'variable',
          detail: g.type || 'global',
        });
        if (g.type === 'image') {
          options.push({ label: `@${g.name} | image }}`, type: 'variable', detail: 'global image' });
        }
      });
    }

    if (afterOpen.startsWith('meta.') || afterOpen === 'meta.') {
      options = [
        { label: 'meta.title',       type: 'property', apply: 'meta.title }}Default{{ /meta.title }}' },
        { label: 'meta.description', type: 'property', apply: 'meta.description }}Default{{ /meta.description }}' },
      ];
    }

    if (afterOpen.startsWith('item.') || afterOpen === 'item.') {
      const allFields = new Set(['title', 'slug', 'url', 'body', 'excerpt', 'featured_image', 'author', 'date', 'status']);
      collections.forEach(c => (c.fields || []).forEach(f => {
        const name = f.name || f;
        if (name) allFields.add(name);
      }));
      options = [...allFields].map(f => ({ label: `item.${f}`, type: 'property' }));
      options.push({ label: 'item.body | raw', type: 'property', detail: 'richtext' });
    }

    if (options.length === 0 && !context.explicit) {
      // Fallback: generic field + filter suggestions
      options = [
        { label: '| raw',      type: 'keyword', detail: 'output HTML as-is' },
        { label: '| image',    type: 'keyword', detail: 'image field' },
        { label: '| link',     type: 'keyword', detail: 'link field' },
        { label: '| textarea', type: 'keyword', detail: 'textarea field' },
        { label: '| select',   type: 'keyword', detail: 'select field' },
        { label: 'meta.title',       type: 'keyword' },
        { label: 'meta.description', type: 'keyword' },
        { label: 'item.',      type: 'property', detail: 'item field (in loop)' },
        ...globals.map(g => ({ label: `@${g.name}`, type: 'variable', detail: 'global' })),
      ];
    }

    return { from: word.from, options, filter: true };
  }

  if (inBlock) {
    const word = context.matchBefore(/\w*/) || { from: context.pos, text: '' };

    const options = [
      {
        label: 'for',
        type: 'keyword',
        detail: 'collection loop',
        apply: 'for item in collection.slug %}\n  {{ item.title }}\n{% endfor %}',
      },
      { label: 'if',        type: 'keyword', apply: 'if condition %}\n  \n{% endif %}' },
      { label: 'else',      type: 'keyword', apply: 'else %}' },
      { label: 'endif',     type: 'keyword', apply: 'endif %}' },
      { label: 'endfor',    type: 'keyword', apply: 'endfor %}' },
      {
        label: 'single',
        type: 'keyword',
        detail: 'single item',
        apply: "single var from collection.slug %}\n  {{ var.title }}\n{% else %}\n  Not found\n{% endsingle %}",
      },
      { label: 'endsingle', type: 'keyword', apply: 'endsingle %}' },
      { label: "include",   type: 'keyword', apply: "include 'partial-name' %}" },
      ...collections.map(c => ({
        label:  `for item in collection.${c.slug}`,
        type:   'keyword',
        detail: c.name || c.slug,
        apply:  `for item in collection.${c.slug} %}\n  {{ item.title }}\n{% endfor %}`,
      })),
    ];

    return { from: word.from, options, filter: true };
  }

  return null;
}
