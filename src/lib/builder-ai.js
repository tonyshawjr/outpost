import { getApiBase, getCsrfToken } from './api.js';

const MAX_NODES = 500;

export function serializeContext(editor) {
  const tree = editor.tree;
  const nodes = [];
  let truncated = false;

  const walk = (id) => {
    if (nodes.length >= MAX_NODES) { truncated = true; return; }
    const n = tree.nodes[id];
    if (!n) return;
    const entry = { id, type: n.type, tag: n.tag };
    if (n.classes?.length) entry.classes = n.classes;
    const text = n.props?.text;
    if (text) entry.text = String(text).slice(0, 120);
    if (n.props?.field) entry.field = n.props.field;
    if (n.type === 'image' && n.props?.src) entry.src = String(n.props.src).slice(0, 200);
    if ((n.type === 'button' || n.type === 'link') && n.props?.href) entry.href = String(n.props.href).slice(0, 200);
    if (n.children?.length) entry.children = n.children.slice();
    nodes.push(entry);
    for (const c of n.children || []) walk(c);
  };
  walk(tree.root);

  const classes = Object.entries(editor.classes || {}).map(([name, decls]) => ({
    name,
    declarations: Object.keys(decls || {}),
  }));

  const tokens = {
    colors: (editor.tokens?.colors || []).map((c) => ({ name: c.name, value: c.value })),
    variables: editor.tokenVars || [],
  };

  return {
    root: tree.root,
    selectedId: editor.selectedId || null,
    nodeCount: nodes.length,
    truncated,
    nodes,
    classes,
    tokens,
  };
}

export async function generateImportSection({ editor, prompt, provider, model, signal, onText, onSection, onError, onDone }) {
  const url = new URL(getApiBase(), window.location.origin);
  url.searchParams.set('action', 'builder/import-ai');

  const tokens = {
    colors: (editor?.tokens?.colors || []).map((c) => ({ name: c.name, value: c.value })),
    variables: editor?.tokenVars || [],
  };

  const res = await fetch(url.toString(), {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': getCsrfToken() },
    credentials: 'include',
    signal,
    body: JSON.stringify({ prompt, context: { tokens }, provider: provider || undefined, model: model || undefined }),
  });

  if (!res.ok) {
    let message = `Request failed (${res.status})`;
    try { message = (await res.json()).error || message; } catch { /* keep default */ }
    onError?.(message);
    return;
  }

  const reader = res.body.getReader();
  const decoder = new TextDecoder();
  let buffer = '';

  while (true) {
    const { done, value } = await reader.read();
    if (done) break;
    buffer += decoder.decode(value, { stream: true });
    const lines = buffer.split('\n');
    buffer = lines.pop();
    for (const line of lines) {
      if (!line.startsWith('data: ')) continue;
      const payload = line.slice(6);
      if (payload === '[DONE]') continue;
      let event;
      try { event = JSON.parse(payload); } catch { continue; }
      if (event.type === 'text') onText?.(event.content || '');
      else if (event.type === 'section') onSection?.({ html: event.html || '', css: event.css || '', js: event.js || '' });
      else if (event.type === 'error') onError?.(event.message || 'Error');
      else if (event.type === 'done') onDone?.(event.usage || null);
    }
  }
}

export async function runBuilderAgent({ editor, messages, provider, model, signal, onText, onOps, onError, onDone }) {
  const url = new URL(getApiBase(), window.location.origin);
  url.searchParams.set('action', 'builder/ai');

  const res = await fetch(url.toString(), {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': getCsrfToken() },
    credentials: 'include',
    signal,
    body: JSON.stringify({
      messages,
      context: serializeContext(editor),
      provider: provider || undefined,
      model: model || undefined,
    }),
  });

  if (!res.ok) {
    let message = `Request failed (${res.status})`;
    try { message = (await res.json()).error || message; } catch { /* keep default */ }
    onError?.(message);
    return;
  }

  const reader = res.body.getReader();
  const decoder = new TextDecoder();
  let buffer = '';

  while (true) {
    const { done, value } = await reader.read();
    if (done) break;
    buffer += decoder.decode(value, { stream: true });
    const lines = buffer.split('\n');
    buffer = lines.pop();
    for (const line of lines) {
      if (!line.startsWith('data: ')) continue;
      const payload = line.slice(6);
      if (payload === '[DONE]') continue;
      let event;
      try { event = JSON.parse(payload); } catch { continue; }
      if (event.type === 'text') onText?.(event.content || '');
      else if (event.type === 'ops') onOps?.(Array.isArray(event.ops) ? event.ops : []);
      else if (event.type === 'error') onError?.(event.message || 'Error');
      else if (event.type === 'done') onDone?.(event.usage || null);
    }
  }
}
