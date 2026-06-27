/**
 * Outpost — Node-tree editor store (v6 page-builder spine).
 *
 * Wraps a node tree with mutations, snapshot-based undo/redo, selection, and
 * load/save against the nodes API. Svelte 5 runes. No UI here — this is the
 * state engine the canvas/layers/panels will bind to later.
 *
 * Undo/redo is snapshot-based: every mutation pushes the prior tree onto the
 * undo stack (deep clones, trees are small). Simple and correct; can move to
 * patch-based history later if trees ever get large.
 */
import * as T from './node-tree.js';
import { nodes as nodesApi, styleClasses as styleClassesApi } from './api.js';

const CLASS_NAME_RE = /^[A-Za-z_][A-Za-z0-9_-]*$/;

const HISTORY_LIMIT = 100;
const snap = (tree) => structuredClone(tree);

export function createNodeEditor() {
  let tree = $state(T.defaultTree());
  let selectedId = $state(null);
  let ownerType = $state('page');
  let ownerId = $state(null);
  let version = $state(0);       // server version (optimistic lock)
  let dirty = $state(false);
  let saving = $state(false);
  let conflict = $state(false);
  let undoStack = $state([]);
  let redoStack = $state([]);
  let classes = $state({});

  function remapTreeClasses(mapFn) {
    const nodes = {};
    for (const id in tree.nodes) {
      const n = tree.nodes[id];
      nodes[id] = { ...n, classes: mapFn(n.classes) };
    }
    tree = { ...tree, nodes };
  }

  /** Apply a tree-producing mutation, recording history. */
  function commit(producer) {
    const prev = tree;
    let result;
    try {
      result = producer(prev);
    } catch {
      return null;
    }
    const nextTree = result && result.tree ? result.tree : result;
    undoStack.push(snap(prev));
    if (undoStack.length > HISTORY_LIMIT) undoStack.shift();
    redoStack = [];
    tree = nextTree;
    dirty = true;
    return result && result.id ? result.id : null;
  }

  return {
    // ── reactive getters ──────────────────────────────
    get tree() { return tree; },
    get selectedId() { return selectedId; },
    get selectedNode() { return selectedId ? tree.nodes[selectedId] : null; },
    get version() { return version; },
    get dirty() { return dirty; },
    get saving() { return saving; },
    get conflict() { return conflict; },
    get canUndo() { return undoStack.length > 0; },
    get canRedo() { return redoStack.length > 0; },
    get classes() { return classes; },
    get classNames() { return Object.keys(classes); },
    get classUsage() {
      const counts = {};
      for (const name in classes) counts[name] = 0;
      for (const id in tree.nodes) {
        for (const c of tree.nodes[id].classes) counts[c] = (counts[c] || 0) + 1;
      }
      return counts;
    },
    get classesCss() {
      let css = '';
      for (const name in classes) {
        const decls = classes[name];
        let body = '';
        for (const prop in decls) {
          const v = decls[prop];
          if (v !== '' && v != null) body += `${prop}:${v};`;
        }
        if (body) css += `.oc-canvas .${name}{${body}}\n`;
      }
      return css;
    },

    // ── selection ─────────────────────────────────────
    select(id) { selectedId = id && tree.nodes[id] ? id : null; },

    // ── mutations (return new node id where relevant) ──
    insert(type, parentId, index = -1, overrides = {}) {
      const id = commit((t) => T.insertNode(t, type, parentId, index, overrides));
      if (id) selectedId = id;
      return id;
    },
    remove(id) {
      const ok = commit((t) => T.deleteNode(t, id)) !== undefined;
      if (selectedId === id) selectedId = null;
      return ok;
    },
    updateProps(id, patch) { return commit((t) => T.updateProps(t, id, patch)); },
    setClasses(id, classes) { return commit((t) => T.setClasses(t, id, classes)); },
    setTag(id, tag) { return commit((t) => T.setTag(t, id, tag)); },
    move(id, newParentId, index = -1) { return commit((t) => T.moveNode(t, id, newParentId, index)); },
    duplicate(id) {
      const newIdv = commit((t) => T.duplicateNode(t, id));
      if (newIdv) selectedId = newIdv;
      return newIdv;
    },

    createClass(name) {
      if (!CLASS_NAME_RE.test(name) || classes[name]) return false;
      classes = { ...classes, [name]: {} };
      dirty = true;
      return true;
    },
    setDeclaration(name, prop, value) {
      if (!classes[name]) return;
      const decls = { ...classes[name] };
      if (value === '' || value == null) delete decls[prop];
      else decls[prop] = value;
      classes = { ...classes, [name]: decls };
      dirty = true;
    },
    addClassToNode(nodeId, name) {
      if (!CLASS_NAME_RE.test(name)) return false;
      if (!classes[name]) classes = { ...classes, [name]: {} };
      const node = tree.nodes[nodeId];
      if (!node || node.classes.includes(name)) return false;
      commit((t) => T.setClasses(t, nodeId, [...node.classes, name]));
      return true;
    },
    removeClassFromNode(nodeId, name) {
      const node = tree.nodes[nodeId];
      if (!node) return;
      commit((t) => T.setClasses(t, nodeId, node.classes.filter((c) => c !== name)));
    },

    renameClass(oldName, newName) {
      newName = (newName || '').trim();
      if (!classes[oldName] || !CLASS_NAME_RE.test(newName) || classes[newName]) return false;
      const next = {};
      for (const name in classes) next[name === oldName ? newName : name] = classes[name];
      classes = next;
      remapTreeClasses((list) => {
        const mapped = list.map((c) => (c === oldName ? newName : c));
        return mapped.filter((c, i) => mapped.indexOf(c) === i);
      });
      dirty = true;
      return true;
    },
    duplicateClass(name) {
      if (!classes[name]) return null;
      let copy = `${name}-copy`;
      let n = 2;
      while (classes[copy]) copy = `${name}-copy-${n++}`;
      classes = { ...classes, [copy]: { ...classes[name] } };
      dirty = true;
      return copy;
    },
    deleteClass(name) {
      if (!classes[name]) return;
      const next = { ...classes };
      delete next[name];
      classes = next;
      remapTreeClasses((list) => list.filter((c) => c !== name));
      dirty = true;
    },

    // ── undo / redo ───────────────────────────────────
    undo() {
      if (!undoStack.length) return;
      redoStack.push(snap(tree));
      tree = undoStack.pop();
      dirty = true;
      if (selectedId && !tree.nodes[selectedId]) selectedId = null;
    },
    redo() {
      if (!redoStack.length) return;
      undoStack.push(snap(tree));
      tree = redoStack.pop();
      dirty = true;
      if (selectedId && !tree.nodes[selectedId]) selectedId = null;
    },

    // ── persistence ───────────────────────────────────
    async load(id, type = 'page') {
      ownerType = type; ownerId = id;
      const [res, cr] = await Promise.all([nodesApi.get(id, type), styleClassesApi.get()]);
      tree = T.validate(res.tree);
      version = res.version || 0;
      const next = {};
      for (const c of cr.classes || []) next[c.name] = c.declarations || {};
      classes = next;
      undoStack = []; redoStack = [];
      dirty = false; conflict = false; selectedId = null;
      return tree;
    },
    async save() {
      if (ownerId == null) throw new Error('Nothing loaded to save');
      saving = true; conflict = false;
      try {
        const res = await nodesApi.save(ownerId, ownerType, T.validate(tree), version);
        version = res.version;
        await styleClassesApi.save(Object.entries(classes).map(([name, declarations]) => ({ name, declarations })));
        dirty = false;
        return res;
      } catch (e) {
        // request() throws a ConflictError on HTTP 409 (stale version).
        if (e && (e.status === 409 || e.name === 'ConflictError')) conflict = true;
        throw e;
      } finally {
        saving = false;
      }
    },
  };
}
