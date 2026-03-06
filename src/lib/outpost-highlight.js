/**
 * CodeMirror 6 extension that highlights Outpost template tags:
 *   {{ ... }}  — output (variable)
 *   {% ... %}  — logic (block)
 *   {# ... #}  — comment
 *
 * Uses ViewPlugin + Decoration.mark so tags overlay on top of HTML highlighting.
 */

export function outpostHighlight(cmView, cmState) {
  const { ViewPlugin, Decoration } = cmView;
  const { RangeSetBuilder } = cmState;

  const outputMark  = Decoration.mark({ class: 'cm-outpost-output' });
  const blockMark   = Decoration.mark({ class: 'cm-outpost-block' });
  const commentMark = Decoration.mark({ class: 'cm-outpost-comment' });

  // Matches {{ }}, {% %}, {# #} including whitespace-trim variants (-? on either side)
  const TAG_RE = /\{\{-?[\s\S]*?-?\}\}|\{%-?[\s\S]*?-?%\}|\{#[\s\S]*?#\}/g;

  function buildDecorations(view) {
    const builder = new RangeSetBuilder();
    for (const { from, to } of view.visibleRanges) {
      const text = view.state.doc.sliceString(from, to);
      TAG_RE.lastIndex = 0;
      let m;
      while ((m = TAG_RE.exec(text)) !== null) {
        const start = from + m.index;
        const end   = start + m[0].length;
        const tag   = m[0];
        if (tag.startsWith('{#'))      builder.add(start, end, commentMark);
        else if (tag.startsWith('{%')) builder.add(start, end, blockMark);
        else                           builder.add(start, end, outputMark);
      }
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
