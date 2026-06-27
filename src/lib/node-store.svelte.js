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
import { nodes as nodesApi } from './api.js';

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
      const res = await nodesApi.get(id, type);
      tree = T.validate(res.tree);
      version = res.version || 0;
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
