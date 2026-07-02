import { Extension } from '@tiptap/core';
import { Plugin, PluginKey } from '@tiptap/pm/state';
import { Decoration, DecorationSet } from '@tiptap/pm/view';

export const grammarKey = new PluginKey('grammar');

export function extractText(doc) {
  let text = '';
  const segs = [];
  let lastParent = null;
  doc.descendants((node, pos, parent) => {
    if (node.isText) {
      if (parent !== lastParent && text.length > 0) text += '\n\n';
      lastParent = parent;
      segs.push({ tStart: text.length, dPos: pos, len: node.text.length });
      text += node.text;
    }
    return true;
  });
  return { text, segs };
}

function textOffsetToDocPos(offset, segs) {
  for (const s of segs) {
    if (offset >= s.tStart && offset <= s.tStart + s.len) return s.dPos + (offset - s.tStart);
  }
  return null;
}

export function mapMatches(matches, segs) {
  const mapped = [];
  for (const m of matches || []) {
    const from = textOffsetToDocPos(m.offset, segs);
    const to = textOffsetToDocPos(m.offset + m.length, segs);
    if (from == null || to == null || to <= from) continue;
    mapped.push({ ...m, from, to });
  }
  return mapped;
}

function buildDecorations(doc, mapped) {
  const decos = mapped.map((m) =>
    Decoration.inline(m.from, m.to, {
      class: `lt-mark lt-${m.issueType === 'misspelling' ? 'spell' : m.issueType === 'style' ? 'style' : 'grammar'}`,
    })
  );
  return DecorationSet.create(doc, decos);
}

export const GrammarExtension = Extension.create({
  name: 'grammar',
  addProseMirrorPlugins() {
    return [
      new Plugin({
        key: grammarKey,
        state: {
          init: () => DecorationSet.empty,
          apply(tr, old) {
            const meta = tr.getMeta(grammarKey);
            if (meta) return buildDecorations(tr.doc, meta.mapped);
            return old.map(tr.mapping, tr.doc);
          },
        },
        props: {
          decorations(state) {
            return grammarKey.getState(state);
          },
        },
      }),
    ];
  },
});

export function setGrammarMatches(view, mapped) {
  view.dispatch(view.state.tr.setMeta(grammarKey, { mapped }));
}

export function clearGrammar(view) {
  view.dispatch(view.state.tr.setMeta(grammarKey, { mapped: [] }));
}
