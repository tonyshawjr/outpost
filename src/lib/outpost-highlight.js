/**
 * CodeMirror 6 extension that highlights Outpost template tags:
 *
 *   v1 Liquid syntax:
 *     {{ ... }}  — output (variable)
 *     {% ... %}  — logic (block)
 *     {# ... #}  — comment
 *
 *   v2 Data-attribute syntax:
 *     data-outpost="field_name"  — field binding (gold/amber)
 *     data-type="richtext"       — field type hint (cyan)
 *     data-scope="global"        — scope modifier (magenta)
 *     data-bind="attr:field"     — attribute binding (cyan)
 *     <outpost-each> etc.        — custom elements (green)
 *     <!-- outpost:block -->     — block comments (amber bg)
 *     <!-- outpost-settings: --> — settings comments (blue bg)
 *
 * Uses ViewPlugin + Decoration.mark so tags overlay on top of HTML highlighting.
 * Colors chosen for HIGH CONTRAST and colorblind accessibility.
 */

export function outpostHighlight(cmView, cmState) {
  const { ViewPlugin, Decoration } = cmView;
  const { RangeSetBuilder } = cmState;

  // v1 marks
  const outputMark  = Decoration.mark({ class: 'cm-outpost-output' });
  const blockMark   = Decoration.mark({ class: 'cm-outpost-block' });
  const commentMark = Decoration.mark({ class: 'cm-outpost-comment' });

  // v2 marks
  const dataOutpostMark  = Decoration.mark({ class: 'cm-outpost-v2-field' });
  const dataTypeMark     = Decoration.mark({ class: 'cm-outpost-v2-type' });
  const dataScopeMark    = Decoration.mark({ class: 'cm-outpost-v2-scope' });
  const dataBindMark     = Decoration.mark({ class: 'cm-outpost-v2-type' }); // same style as type
  const customElemMark   = Decoration.mark({ class: 'cm-outpost-v2-elem' });
  const blockCommentMark = Decoration.mark({ class: 'cm-outpost-v2-block-comment' });
  const settingsCommentMark = Decoration.mark({ class: 'cm-outpost-v2-settings-comment' });

  // v1: Matches {{ }}, {% %}, {# #} including whitespace-trim variants
  const TAG_RE = /\{\{-?[\s\S]*?-?\}\}|\{%-?[\s\S]*?-?%\}|\{#[\s\S]*?#\}/g;

  // v2: data-outpost="value" (captures the whole attribute including quotes)
  const DATA_OUTPOST_RE = /data-outpost\s*=\s*"[^"]*"/g;

  // v2: data-type="value"
  const DATA_TYPE_RE = /data-type\s*=\s*"[^"]*"/g;

  // v2: data-scope="value"
  const DATA_SCOPE_RE = /data-scope\s*=\s*"[^"]*"/g;

  // v2: data-bind="value"
  const DATA_BIND_RE = /data-bind\s*=\s*"[^"]*"/g;

  // v2: <outpost-each>, </outpost-each>, <outpost-single>, etc. — match the tag name
  const CUSTOM_ELEM_RE = /<\/?outpost-(?:each|single|if|include|menu|meta|seo|pagination)\b/g;

  // v2: <!-- outpost:blockname --> and <!-- /outpost:blockname -->
  const BLOCK_COMMENT_RE = /<!--\s*\/?outpost:[^>]*-->/g;

  // v2: <!-- outpost-settings: ... -->
  const SETTINGS_COMMENT_RE = /<!--\s*outpost-settings:[^>]*-->/g;

  function addMatches(text, from, regex, mark, builder) {
    regex.lastIndex = 0;
    let m;
    while ((m = regex.exec(text)) !== null) {
      builder.push({ from: from + m.index, to: from + m.index + m[0].length, mark });
    }
  }

  function buildDecorations(view) {
    const allDecos = [];

    for (const { from, to } of view.visibleRanges) {
      const text = view.state.doc.sliceString(from, to);

      // v1 Liquid tags
      TAG_RE.lastIndex = 0;
      let m;
      while ((m = TAG_RE.exec(text)) !== null) {
        const start = from + m.index;
        const end   = start + m[0].length;
        const tag   = m[0];
        if (tag.startsWith('{#'))      allDecos.push({ from: start, to: end, mark: commentMark });
        else if (tag.startsWith('{%')) allDecos.push({ from: start, to: end, mark: blockMark });
        else                           allDecos.push({ from: start, to: end, mark: outputMark });
      }

      // v2 data attributes
      addMatches(text, from, DATA_OUTPOST_RE, dataOutpostMark, allDecos);
      addMatches(text, from, DATA_TYPE_RE, dataTypeMark, allDecos);
      addMatches(text, from, DATA_SCOPE_RE, dataScopeMark, allDecos);
      addMatches(text, from, DATA_BIND_RE, dataBindMark, allDecos);

      // v2 custom elements — just the tag name portion
      addMatches(text, from, CUSTOM_ELEM_RE, customElemMark, allDecos);

      // v2 block comments (check settings first since it's more specific)
      addMatches(text, from, SETTINGS_COMMENT_RE, settingsCommentMark, allDecos);
      addMatches(text, from, BLOCK_COMMENT_RE, blockCommentMark, allDecos);
    }

    // Sort by from position (required by RangeSetBuilder)
    allDecos.sort((a, b) => a.from - b.from || a.to - b.to);

    // Deduplicate overlapping ranges — settings comments also match block comments,
    // so keep the first (more specific) match when ranges overlap
    const deduped = [];
    let lastEnd = -1;
    for (const d of allDecos) {
      if (d.from >= lastEnd) {
        deduped.push(d);
        lastEnd = d.to;
      }
    }

    const builder = new RangeSetBuilder();
    for (const d of deduped) {
      builder.add(d.from, d.to, d.mark);
    }
    return builder.finish();
  }

  return ViewPlugin.fromClass(
    class {
      constructor(view) { this.decorations = buildDecorations(view); }
      update(update) {
        if (update.docChanged || update.viewportChanged) {
          this.decorations = buildDecorations(update.view);
        }
      }
    },
    { decorations: (v) => v.decorations }
  );
}
