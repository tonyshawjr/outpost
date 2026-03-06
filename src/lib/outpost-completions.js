/**
 * Outpost Liquid template autocomplete source for CodeMirror 6
 */

let _ctx = { globals: [], collections: [] };

export function setOutpostContext(ctx) {
  _ctx = ctx || { globals: [], collections: [] };
}

/**
 * Completion source — registers via htmlLanguage.data
 * Provides Liquid {{ }} and {% %} completions in HTML files.
 */
export function outpostCompletionSource(context) {
  const { globals = [], collections = [] } = _ctx;

  const line = context.state.doc.lineAt(context.pos);
  const before = line.text.slice(0, context.pos - line.from);

  // Detect if inside {{ ... }}
  const lastOpen2  = before.lastIndexOf('{{');
  const lastClose2 = before.lastIndexOf('}}');
  const inDouble   = lastOpen2 >= 0 && lastOpen2 > lastClose2;

  // Detect if inside {% ... %}
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
