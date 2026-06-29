import * as T from './node-tree.js';
import * as TOK from './builder-tokens.js';
import { nodes as nodesApi, styleClasses as styleClassesApi, nodeComponents as componentsApi, designTokens as tokensApi } from './api.js';

const CLASS_NAME_RE = /^[A-Za-z_][A-Za-z0-9_-]*$/;

function bemElementName(node) {
  if (node.type === 'text') return /^h[1-6]$/.test(node.tag) ? 'title' : 'text';
  if (node.type === 'image') return 'image';
  if (node.type === 'button') return 'button';
  if (node.type === 'link') return 'link';
  return 'group';
}

const addUniq = (list, name) => (list.includes(name) ? list : [...list, name]);
const HISTORY_LIMIT = 100;
const snap = (tree) => JSON.parse(JSON.stringify(tree));

function newComponentId() {
  let s = 'c_';
  for (let i = 0; i < 10; i++) s += '0123456789abcdef'[(Math.random() * 16) | 0];
  return s;
}

export function createNodeEditor() {
  let pageTree = $state(T.defaultTree());
  let comps = $state({});
  let editingComponentId = $state(null);
  let selectedId = $state(null);
  let ownerType = $state('page');
  let ownerId = $state(null);
  let version = $state(0);
  let dirty = $state(false);
  let saving = $state(false);
  let conflict = $state(false);
  let undoStack = $state([]);
  let redoStack = $state([]);
  let classes = $state({});
  let tokens = $state(TOK.defaultTokens());

  function getTree() {
    return editingComponentId && comps[editingComponentId] ? comps[editingComponentId].tree : pageTree;
  }
  function setTree(t) {
    if (editingComponentId && comps[editingComponentId]) {
      comps = { ...comps, [editingComponentId]: { ...comps[editingComponentId], tree: t } };
    } else {
      pageTree = t;
    }
  }

  function remapAllClasses(mapFn) {
    const remap = (t) => {
      const nodes = {};
      for (const id in t.nodes) nodes[id] = { ...t.nodes[id], classes: mapFn(t.nodes[id].classes) };
      return { ...t, nodes };
    };
    pageTree = remap(pageTree);
    const nc = {};
    for (const cid in comps) nc[cid] = { ...comps[cid], tree: remap(comps[cid].tree) };
    comps = nc;
  }

  function pushHistory(prev) {
    undoStack.push(snap(prev));
    if (undoStack.length > HISTORY_LIMIT) undoStack.shift();
    redoStack = [];
  }

  function commit(producer) {
    const prev = getTree();
    let result;
    try {
      result = producer(prev);
    } catch {
      return null;
    }
    const nextTree = result && result.tree ? result.tree : result;
    pushHistory(prev);
    setTree(nextTree);
    dirty = true;
    return result && result.id ? result.id : null;
  }

  function resetHistory() {
    undoStack = [];
    redoStack = [];
  }

  return {
    get tree() { return getTree(); },
    get selectedId() { return selectedId; },
    get selectedNode() { return selectedId ? getTree().nodes[selectedId] : null; },
    get version() { return version; },
    get dirty() { return dirty; },
    get saving() { return saving; },
    get conflict() { return conflict; },
    get canUndo() { return undoStack.length > 0; },
    get canRedo() { return redoStack.length > 0; },
    get classes() { return classes; },
    get classNames() { return Object.keys(classes); },

    get components() { return comps; },
    get componentsList() { return Object.values(comps); },
    get editingComponentId() { return editingComponentId; },
    get editingComponentName() { return editingComponentId && comps[editingComponentId] ? comps[editingComponentId].name : null; },
    componentName(id) { return comps[id] ? comps[id].name : null; },

    get classUsage() {
      const counts = {};
      for (const name in classes) counts[name] = 0;
      const tally = (t) => {
        for (const id in t.nodes) for (const c of t.nodes[id].classes) counts[c] = (counts[c] || 0) + 1;
      };
      tally(pageTree);
      for (const cid in comps) tally(comps[cid].tree);
      return counts;
    },
    get tokens() { return tokens; },
    get tokenVars() { return TOK.tokenVarNames(tokens); },
    get classesCss() {
      let css = TOK.tokensToCss(tokens);
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

    select(id) { selectedId = id && getTree().nodes[id] ? id : null; },

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

    applyBem(nodeId, block) {
      block = (block || '').trim();
      if (!CLASS_NAME_RE.test(block)) return false;
      const t = getTree();
      if (!t.nodes[nodeId]) return false;
      pushHistory(t);

      const order = [];
      const walk = (id) => { order.push(id); for (const c of t.nodes[id].children || []) walk(c); };
      walk(nodeId);

      const nextClasses = { ...classes };
      const ensure = (name) => { if (!nextClasses[name]) nextClasses[name] = {}; };
      const nodes = { ...t.nodes };
      const assigned = new Set();

      ensure(block);
      nodes[nodeId] = { ...nodes[nodeId], classes: addUniq(nodes[nodeId].classes, block) };
      assigned.add(block);

      for (const id of order) {
        if (id === nodeId) continue;
        const n = t.nodes[id];
        if (n.type === 'component-ref') continue;
        const base = `${block}__${bemElementName(n)}`;
        let name = base;
        let i = 2;
        while (assigned.has(name)) name = `${base}-${i++}`;
        assigned.add(name);
        ensure(name);
        nodes[id] = { ...n, classes: addUniq(n.classes, name) };
      }

      classes = nextClasses;
      setTree({ ...t, nodes });
      selectedId = nodeId;
      dirty = true;
      return true;
    },
    componentize(nodeId, name) {
      const t = getTree();
      if (nodeId === t.root) return null;
      const parentId = T.parentOf(t, nodeId);
      if (!parentId) return null;
      const node = t.nodes[nodeId];
      if (node.type === 'component-ref') return null;
      const index = t.nodes[parentId].children.indexOf(nodeId);
      const cid = newComponentId();
      pushHistory(t);
      comps = { ...comps, [cid]: { id: cid, name: (name || 'Component').trim() || 'Component', tree: T.extractSubtree(t, nodeId) } };
      const without = T.deleteNode(t, nodeId);
      const r = T.insertNode(without, 'component-ref', parentId, index, { props: { componentId: cid } });
      setTree(r.tree);
      selectedId = r.id;
      dirty = true;
      return cid;
    },
    enterComponent(id) {
      if (!comps[id]) return;
      editingComponentId = id;
      resetHistory();
      selectedId = null;
    },
    exitComponent() {
      editingComponentId = null;
      resetHistory();
      selectedId = null;
    },
    renameComponent(id, name) {
      if (!comps[id]) return;
      name = (name || '').trim();
      if (!name) return;
      comps = { ...comps, [id]: { ...comps[id], name } };
      dirty = true;
    },

    addColorToken(name, value) {
      name = (name || '').trim();
      if (!TOK.colorNameValid(name) || (tokens.colors || []).some((c) => c.name === name)) return false;
      tokens = { ...tokens, colors: [...(tokens.colors || []), { name, value: value || '#888888', utilities: true }] };
      dirty = true;
      return true;
    },
    updateColorToken(name, patch) {
      tokens = { ...tokens, colors: (tokens.colors || []).map((c) => (c.name === name ? { ...c, ...patch } : c)) };
      dirty = true;
    },
    removeColorToken(name) {
      tokens = { ...tokens, colors: (tokens.colors || []).filter((c) => c.name !== name) };
      dirty = true;
    },
    setScaleOption(scale, key, value) {
      if (scale !== 'type' && scale !== 'spacing') return;
      const num = Number(value);
      if (!Number.isFinite(num)) return;
      tokens = { ...tokens, [scale]: { ...tokens[scale], [key]: num } };
      dirty = true;
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
      const node = getTree().nodes[nodeId];
      if (!node || node.classes.includes(name)) return false;
      commit((t) => T.setClasses(t, nodeId, [...node.classes, name]));
      return true;
    },
    removeClassFromNode(nodeId, name) {
      const node = getTree().nodes[nodeId];
      if (!node) return;
      commit((t) => T.setClasses(t, nodeId, node.classes.filter((c) => c !== name)));
    },

    renameClass(oldName, newName) {
      newName = (newName || '').trim();
      if (!classes[oldName] || !CLASS_NAME_RE.test(newName) || classes[newName]) return false;
      const next = {};
      for (const name in classes) next[name === oldName ? newName : name] = classes[name];
      classes = next;
      remapAllClasses((list) => {
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
      remapAllClasses((list) => list.filter((c) => c !== name));
      dirty = true;
    },

    undo() {
      if (!undoStack.length) return;
      redoStack.push(snap(getTree()));
      setTree(undoStack.pop());
      dirty = true;
      if (selectedId && !getTree().nodes[selectedId]) selectedId = null;
    },
    redo() {
      if (!redoStack.length) return;
      undoStack.push(snap(getTree()));
      setTree(redoStack.pop());
      dirty = true;
      if (selectedId && !getTree().nodes[selectedId]) selectedId = null;
    },

    async load(id, type = 'page') {
      ownerType = type; ownerId = id;
      const [res, cr, comp, tk] = await Promise.all([nodesApi.get(id, type), styleClassesApi.get(), componentsApi.get(), tokensApi.get()]);
      pageTree = T.validate(res.tree);
      version = res.version || 0;
      const nextClasses = {};
      for (const c of cr.classes || []) nextClasses[c.name] = c.declarations || {};
      classes = nextClasses;
      const nextComps = {};
      for (const c of comp.components || []) {
        try { nextComps[c.id] = { id: c.id, name: c.name, tree: T.validate(c.tree) }; } catch { void 0; }
      }
      comps = nextComps;
      tokens = tk.tokens && tk.tokens.colors ? { ...TOK.defaultTokens(), ...tk.tokens } : TOK.defaultTokens();
      editingComponentId = null;
      resetHistory();
      dirty = false; conflict = false; selectedId = null;
      return pageTree;
    },
    async save() {
      if (ownerId == null) throw new Error('Nothing loaded to save');
      saving = true; conflict = false;
      try {
        const res = await nodesApi.save(ownerId, ownerType, T.validate(pageTree), version);
        version = res.version;
        await styleClassesApi.save(Object.entries(classes).map(([name, declarations]) => ({ name, declarations })));
        await componentsApi.save(Object.values(comps).map((c) => ({ id: c.id, name: c.name, tree: c.tree })));
        await tokensApi.save(tokens);
        dirty = false;
        return res;
      } catch (e) {
        if (e && (e.status === 409 || e.name === 'ConflictError')) conflict = true;
        throw e;
      } finally {
        saving = false;
      }
    },
  };
}
