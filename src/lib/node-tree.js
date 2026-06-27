/**
 * Outpost — Node-Tree model (v6 visual page-builder spine, client side).
 *
 * Pure, framework-agnostic operations over the flat-map tree:
 *   { root, nodes: { <id>: { id, type, tag, props, classes, styles, children } } }
 *
 * Mirrors php/node-engine.php. Every mutation returns a NEW tree (immutable),
 * so the editor store can snapshot for undo/redo and Svelte sees fresh refs.
 * No DOM, no Svelte, no I/O — unit-testable in plain node.
 */

/** Node type registry. `tags[0]` is the default tag. */
export const NODE_TYPES = {
  container: { tags: ['div', 'section', 'main', 'header', 'footer', 'article', 'aside', 'nav', 'ul', 'ol', 'li', 'figure'], children: true, void: false },
  text:      { tags: ['p', 'span', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'strong', 'em', 'small', 'blockquote', 'label'], children: false, void: false },
  image:     { tags: ['img'], children: false, void: true },
  button:    { tags: ['button', 'a'], children: false, void: false },
  link:      { tags: ['a'], children: false, void: false },
};

/** Default props per type, used when creating a node. */
const DEFAULT_PROPS = {
  container: {},
  text: { text: 'Text' },
  image: { src: '', alt: '' },
  button: { text: 'Button', href: '' },
  link: { text: 'Link', href: '#' },
};

const HEX = '0123456789abcdef';
/** Generate an id matching the PHP format: n_ + 10 hex chars. */
export function newId() {
  let s = 'n_';
  for (let i = 0; i < 10; i++) s += HEX[(Math.random() * 16) | 0];
  return s;
}

/** A fresh empty tree: a single body container as root. */
export function defaultTree() {
  const root = newId();
  return {
    root,
    nodes: {
      [root]: { id: root, type: 'container', tag: 'main', props: {}, classes: [], styles: {}, children: [] },
    },
  };
}

/** Build a node of `type`, with optional overrides. */
export function makeNode(type, overrides = {}) {
  const def = NODE_TYPES[type];
  if (!def) throw new Error(`Unknown node type: ${type}`);
  return {
    id: overrides.id || newId(),
    type,
    tag: def.tags.includes(overrides.tag) ? overrides.tag : def.tags[0],
    props: { ...DEFAULT_PROPS[type], ...(overrides.props || {}) },
    classes: Array.isArray(overrides.classes) ? [...overrides.classes] : [],
    styles: overrides.styles ? { ...overrides.styles } : {},
    children: [],
  };
}

export const getNode = (tree, id) => tree.nodes[id];
export const canHaveChildren = (node) => !!NODE_TYPES[node.type]?.children;

/** Find the parent id of a node (or null for the root / orphans). */
export function parentOf(tree, id) {
  for (const nid in tree.nodes) {
    if (tree.nodes[nid].children?.includes(id)) return nid;
  }
  return null;
}

/** True if `maybeAncestor` is `id` or an ancestor of it (cycle guard for moves). */
export function isAncestor(tree, maybeAncestor, id) {
  let cur = id;
  while (cur) {
    if (cur === maybeAncestor) return true;
    cur = parentOf(tree, cur);
  }
  return false;
}

const clone = (tree) => structuredClone(tree);

/**
 * Insert a freshly-built node of `type` under `parentId` at `index`
 * (default: append). Returns { tree, id }.
 */
export function insertNode(tree, type, parentId, index = -1, overrides = {}) {
  const next = clone(tree);
  const parent = next.nodes[parentId];
  if (!parent) throw new Error(`No parent ${parentId}`);
  if (!canHaveChildren(parent)) throw new Error(`${parent.type} cannot have children`);
  const node = makeNode(type, overrides);
  next.nodes[node.id] = node;
  const at = index < 0 || index > parent.children.length ? parent.children.length : index;
  parent.children.splice(at, 0, node.id);
  return { tree: next, id: node.id };
}

/** Delete a node and its whole subtree. Root cannot be deleted. */
export function deleteNode(tree, id) {
  if (id === tree.root) throw new Error('Cannot delete root');
  const next = clone(tree);
  const parent = parentOf(next, id);
  if (parent) {
    const p = next.nodes[parent];
    p.children = p.children.filter((c) => c !== id);
  }
  // Remove the subtree from the node map.
  const stack = [id];
  while (stack.length) {
    const nid = stack.pop();
    const n = next.nodes[nid];
    if (!n) continue;
    if (n.children) stack.push(...n.children);
    delete next.nodes[nid];
  }
  return next;
}

/** Shallow-merge a patch into a node's props. */
export function updateProps(tree, id, patch) {
  const next = clone(tree);
  const n = next.nodes[id];
  if (!n) throw new Error(`No node ${id}`);
  n.props = { ...n.props, ...patch };
  return next;
}

/** Replace a node's class list (sanitised, de-duped). */
export function setClasses(tree, id, classes) {
  const next = clone(tree);
  const n = next.nodes[id];
  if (!n) throw new Error(`No node ${id}`);
  const seen = new Set();
  n.classes = (classes || [])
    .map((c) => String(c).replace(/[^A-Za-z0-9_-]/g, ''))
    .filter((c) => c && !seen.has(c) && seen.add(c));
  return next;
}

/** Change a node's tag (must be valid for its type). */
export function setTag(tree, id, tag) {
  const next = clone(tree);
  const n = next.nodes[id];
  if (!n) throw new Error(`No node ${id}`);
  if (!NODE_TYPES[n.type].tags.includes(tag)) throw new Error(`Invalid tag ${tag} for ${n.type}`);
  n.tag = tag;
  return next;
}

/** Move a node under a new parent at index. Guards against cycles. */
export function moveNode(tree, id, newParentId, index = -1) {
  if (id === tree.root) throw new Error('Cannot move root');
  if (isAncestor(tree, id, newParentId)) throw new Error('Cannot move a node into its own descendant');
  const next = clone(tree);
  const newParent = next.nodes[newParentId];
  if (!newParent) throw new Error(`No parent ${newParentId}`);
  if (!canHaveChildren(newParent)) throw new Error(`${newParent.type} cannot have children`);
  const oldParentId = parentOf(next, id);
  if (oldParentId) {
    const op = next.nodes[oldParentId];
    op.children = op.children.filter((c) => c !== id);
  }
  const at = index < 0 || index > newParent.children.length ? newParent.children.length : index;
  newParent.children.splice(at, 0, id);
  return next;
}

/** Deep-clone a node + subtree (fresh ids) and insert right after the source. */
export function duplicateNode(tree, id) {
  if (id === tree.root) throw new Error('Cannot duplicate root');
  const parentId = parentOf(tree, id);
  if (!parentId) throw new Error('Orphan node');
  const next = clone(tree);

  const cloneSubtree = (srcId) => {
    const src = next.nodes[srcId];
    const copy = { ...structuredClone(src), id: newId(), children: [] };
    next.nodes[copy.id] = copy;
    for (const cid of src.children || []) copy.children.push(cloneSubtree(cid));
    return copy.id;
  };

  const newRootId = cloneSubtree(id);
  const p = next.nodes[parentId];
  const idx = p.children.indexOf(id);
  p.children.splice(idx + 1, 0, newRootId);
  return { tree: next, id: newRootId };
}

/**
 * Light client-side validation/normalisation, mirroring the PHP engine:
 * clamp tags, sanitise classes, drop dangling child refs, break cycles,
 * drop orphans. Returns a clean tree.
 */
export function validate(tree) {
  if (!tree || typeof tree.root !== 'string' || !tree.nodes) throw new Error('Malformed tree');
  const clean = {};
  for (const id in tree.nodes) {
    const node = tree.nodes[id];
    const def = NODE_TYPES[node?.type];
    if (!def) continue;
    const seen = new Set();
    clean[id] = {
      id,
      type: node.type,
      tag: def.tags.includes(node.tag) ? node.tag : def.tags[0],
      props: node.props && typeof node.props === 'object' ? node.props : {},
      classes: (node.classes || []).map((c) => String(c).replace(/[^A-Za-z0-9_-]/g, '')).filter((c) => c && !seen.has(c) && seen.add(c)),
      styles: node.styles && typeof node.styles === 'object' ? node.styles : {},
      children: def.children && Array.isArray(node.children) ? node.children.slice() : [],
    };
  }
  if (!clean[tree.root]) throw new Error('Root not found');
  for (const id in clean) clean[id].children = clean[id].children.filter((c) => clean[c]);

  const visited = new Set();
  const walk = (id) => {
    if (visited.has(id)) return false;
    visited.add(id);
    clean[id].children = clean[id].children.filter((c) => clean[c] && walk(c));
    return true;
  };
  walk(tree.root);

  const nodes = {};
  for (const id of visited) nodes[id] = clean[id];
  return { root: tree.root, nodes };
}
