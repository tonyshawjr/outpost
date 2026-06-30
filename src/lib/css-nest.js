const PROP_RE = /^-?[A-Za-z][A-Za-z0-9-]*$/;

function stripComments(css) {
  return css.replace(/\/\*[\s\S]*?\*\//g, '');
}

function splitEntries(body) {
  const entries = [];
  let buf = '';
  let depth = 0;
  let quote = '';
  for (let i = 0; i < body.length; i++) {
    const ch = body[i];
    if (quote) {
      buf += ch;
      if (ch === quote && body[i - 1] !== '\\') quote = '';
      continue;
    }
    if (ch === '"' || ch === "'") { quote = ch; buf += ch; continue; }
    if (ch === '(') { depth++; buf += ch; continue; }
    if (ch === ')') { depth = Math.max(0, depth - 1); buf += ch; continue; }
    if (ch === '{' && depth === 0) {
      const selector = buf.trim();
      let braces = 1;
      let inner = '';
      let q = '';
      i++;
      for (; i < body.length; i++) {
        const c = body[i];
        if (q) {
          inner += c;
          if (c === q && body[i - 1] !== '\\') q = '';
          continue;
        }
        if (c === '"' || c === "'") { q = c; inner += c; continue; }
        if (c === '{') braces++;
        else if (c === '}') { braces--; if (braces === 0) break; }
        inner += c;
      }
      entries.push({ type: 'block', selector, inner });
      buf = '';
      continue;
    }
    if (ch === ';' && depth === 0) {
      if (buf.trim()) entries.push({ type: 'decl', text: buf.trim() });
      buf = '';
      continue;
    }
    buf += ch;
  }
  if (buf.trim()) entries.push({ type: 'decl', text: buf.trim() });
  return entries;
}

function splitDecl(text) {
  let depth = 0;
  let quote = '';
  for (let i = 0; i < text.length; i++) {
    const ch = text[i];
    if (quote) { if (ch === quote && text[i - 1] !== '\\') quote = ''; continue; }
    if (ch === '"' || ch === "'") { quote = ch; continue; }
    if (ch === '(') depth++;
    else if (ch === ')') depth = Math.max(0, depth - 1);
    else if (ch === ':' && depth === 0) {
      return [text.slice(0, i).trim(), text.slice(i + 1).trim()];
    }
  }
  return null;
}

export function parseCssBody(text) {
  const obj = {};
  for (const entry of splitEntries(stripComments(String(text || '')))) {
    if (entry.type === 'block') {
      const sel = entry.selector;
      if (!sel) continue;
      obj[sel] = parseCssBody(entry.inner);
    } else {
      const pair = splitDecl(entry.text);
      if (!pair) continue;
      const [prop, value] = pair;
      if (prop && value) obj[prop] = value;
    }
  }
  return obj;
}

export function serializeCssBody(decls, indent = 0) {
  const pad = '  '.repeat(indent);
  let out = '';
  for (const key in decls) {
    const v = decls[key];
    if (!(v && typeof v === 'object') && v != null && v !== '') {
      out += `${pad}${key}: ${v};\n`;
    }
  }
  for (const key in decls) {
    const v = decls[key];
    if (v && typeof v === 'object') {
      out += `${pad}${key} {\n${serializeCssBody(v, indent + 1)}${pad}}\n`;
    }
  }
  return out;
}

export function isSelectorKey(key) {
  return key.startsWith('&') || key.startsWith('@') || /[ .:>+~\[]/.test(key);
}

export function nestedKeyValid(key) {
  if (!key || key.length > 200) return false;
  if (key.startsWith('@')) return /^@(media|container|supports)\b[^{}<>;]*$/i.test(key);
  if (/[{}<;@]/.test(key)) return false;
  if (!/^[A-Za-z0-9 &.:>+~\[\]="'(),_-]+$/.test(key)) return false;
  let q = '';
  let paren = 0;
  let bracket = 0;
  for (const ch of key) {
    if (q) { if (ch === q) q = ''; continue; }
    if (ch === '"' || ch === "'") q = ch;
    else if (ch === '(') paren++;
    else if (ch === ')') { if (--paren < 0) return false; }
    else if (ch === '[') bracket++;
    else if (ch === ']') { if (--bracket < 0) return false; }
    else if (ch === ',' && paren === 0 && bracket === 0) return false;
  }
  return q === '' && paren === 0 && bracket === 0;
}

function flatProps(decls) {
  const out = {};
  for (const key in decls) {
    const v = decls[key];
    if (typeof v === 'string' && !isSelectorKey(key) && PROP_RE.test(key)) out[key] = v;
  }
  return out;
}

function emitRule(selector, decls) {
  let out = '';
  const base = flatProps(decls);
  let body = '';
  for (const prop in base) if (base[prop] !== '') body += `${prop}:${base[prop]};`;
  if (body) out += `${selector}{${body}}\n`;
  for (const key in decls) {
    const v = decls[key];
    if (!v || typeof v !== 'object') continue;
    if (!nestedKeyValid(key)) continue;
    if (key.startsWith('@')) {
      out += `${key}{${emitRule(selector, v)}}\n`;
    } else {
      const child = key.includes('&') ? key.replaceAll('&', selector) : `${selector} ${key}`;
      out += emitRule(child, v);
    }
  }
  return out;
}

export function emitClassCss(scope, name, decls) {
  const selector = `${scope}.${name}`;
  return emitRule(selector, decls || {});
}

export function parseCustomMedia(text) {
  const map = {};
  const re = /@custom-media\s+--([A-Za-z0-9_-]+)\s+([^;{}]+);/gi;
  let m;
  while ((m = re.exec(String(text || '')))) {
    let cond = m[2].trim();
    if (cond.startsWith('(') && cond.endsWith(')')) cond = cond.slice(1, -1).trim();
    if (cond && cond.length <= 200 && /^[A-Za-z0-9 ():<>=,.\/-]+$/.test(cond)) map[m[1]] = cond;
  }
  return map;
}

export function expandCustomMedia(css, map) {
  if (!map || !Object.keys(map).length) return css;
  return String(css || '').replace(/@(media|container)([^{]*)\{/gi, (full, type, prelude) => {
    const expanded = prelude.replace(/\(\s*--([A-Za-z0-9_-]+)\s*\)/g, (mm, name) =>
      Object.prototype.hasOwnProperty.call(map, name) ? `(${map[name]})` : mm
    );
    return `@${type}${expanded}{`;
  });
}

export function sanitizeRawCss(css) {
  if (!css) return '';
  return String(css)
    .slice(0, 100000)
    .replace(/</g, '')
    .replace(/@import\b[^;]*;?/gi, '')
    .replace(/@charset\b[^;]*;?/gi, '')
    .replace(/expression\s*\(/gi, '')
    .replace(/(javascript|vbscript)\s*:/gi, '');
}

export function topLevelProps(decls) {
  return flatProps(decls || {});
}

export function setTopLevelProp(decls, prop, value) {
  const next = {};
  let placed = false;
  for (const key in decls) {
    if (key === prop) {
      if (value !== '' && value != null) { next[key] = value; }
      placed = true;
    } else {
      next[key] = decls[key];
    }
  }
  if (!placed && value !== '' && value != null) next[prop] = value;
  return next;
}
