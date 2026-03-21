/**
 * Forge — Pure tag wrapping functions for Outpost v2 data-attribute templates.
 * Each function takes a config object and returns the output string to insert.
 */

/**
 * Make an element editable by adding data-outpost attributes.
 * For elements with selected text, adds attributes to the wrapping element.
 * For standalone use, returns a placeholder span with the attributes.
 */
export function wrapEditable({ fieldName, type = 'text', scope = 'page', useDefault = false, selectedText = '', editable = false }) {
  const name = scope === 'global' ? `@${sanitizeName(fieldName)}` : sanitizeName(fieldName);
  const attrs = buildAttrs(name, type, editable);

  // If we have selected text with a wrapping HTML element, inject attrs into it
  if (selectedText) {
    const injected = injectAttrsIntoTag(selectedText, attrs);
    if (injected) return injected;
  }

  // No wrapping element — return a span with the attributes
  const inner = useDefault && selectedText ? selectedText : '';
  return `<span ${attrs}>${inner}</span>`;
}

/**
 * Wrap selection in a collection loop: <outpost-each collection="slug">...</outpost-each>
 */
export function wrapCollectionLoop({ collectionSlug, itemVar = 'item', limit = '', orderby = '', selectedText = '' }) {
  const slug = sanitizeName(collectionSlug);
  let attrs = `collection="${slug}"`;
  if (limit) attrs += ` limit="${sanitizeName(limit)}"`;
  if (orderby) attrs += ` orderby="${sanitizeName(orderby)}"`;

  const inner = selectedText || '  <!-- collection items -->';
  const indent = detectIndent(selectedText || inner);

  return `<outpost-each ${attrs}>\n${indentBlock(inner, indent)}\n${indent}</outpost-each>`;
}

/**
 * Wrap selection in a conditional: <outpost-if field="name" exists>...</outpost-if>
 */
export function wrapConditional({ expression, operator = 'truthy', value = '', selectedText = '' }) {
  const field = sanitizeName(expression);
  let attrs = `field="${field}"`;
  if (operator === 'truthy') {
    attrs += ' exists';
  } else if (operator === '==' && value) {
    attrs += ` equals="${value.replace(/"/g, '&quot;')}"`;
  } else if (operator === '!=' && value) {
    attrs += ` not="${value.replace(/"/g, '&quot;')}"`;
  }

  const inner = selectedText || '  ';
  const indent = detectIndent(selectedText || inner);

  return `<outpost-if ${attrs}>\n${indentBlock(inner, indent)}\n${indent}</outpost-if>`;
}

/**
 * Return an include tag for a partial.
 */
export function wrapPartialInclude(partialName) {
  return `<outpost-include partial="${sanitizeName(partialName)}" />`;
}

/**
 * Return a meta/SEO tag: <outpost-seo /> (handles both title and description).
 */
export function wrapMeta({ metaType = 'title', selectedText = '' }) {
  return `<outpost-seo />`;
}

/**
 * Wrap selection in a menu loop: <outpost-menu name="slug">...</outpost-menu>
 * Replaces static <a> links with dynamic data-outpost attributes.
 */
export function wrapMenuLoop({ menuSlug, linkVar = 'link', selectedText = '', applyMapping = false }) {
  const slug = sanitizeName(menuSlug);
  let inner = selectedText || `  <a data-outpost="url" data-type="link">Menu Item</a>`;

  if (applyMapping && selectedText) {
    inner = applyMenuMappings(selectedText, linkVar);
  }

  const indent = detectIndent(inner);
  return `<outpost-menu name="${slug}">\n${indentBlock(inner, indent)}\n${indent}</outpost-menu>`;
}

/**
 * Replace static links in the first <li> or <a> with menu template tags.
 * Keeps only the first link element as the loop template.
 */
function applyMenuMappings(html, linkVar) {
  // Find all <li>...</li> blocks
  const liRe = /<li\b[^>]*>[\s\S]*?<\/li>/gi;
  const liMatches = [...html.matchAll(liRe)];

  if (liMatches.length > 1) {
    // Multiple <li> items — keep just the first one, replace its content
    const firstLi = liMatches[0][0];
    const mapped = mapSingleLink(firstLi, linkVar);
    // Replace entire inner content (all <li>s) with just the mapped first one
    const firstStart = liMatches[0].index;
    const lastEnd = liMatches[liMatches.length - 1].index + liMatches[liMatches.length - 1][0].length;
    return html.substring(0, firstStart) + mapped + html.substring(lastEnd);
  }

  // No <li> — try <a> tags directly
  const aRe = /<a\b[^>]*href\s*=\s*["'][^"']*["'][^>]*>[\s\S]*?<\/a>/gi;
  const aMatches = [...html.matchAll(aRe)];

  if (aMatches.length > 1) {
    const firstA = aMatches[0][0];
    const mapped = mapSingleLink(firstA, linkVar);
    const firstStart = aMatches[0].index;
    const lastEnd = aMatches[aMatches.length - 1].index + aMatches[aMatches.length - 1][0].length;
    return html.substring(0, firstStart) + mapped + html.substring(lastEnd);
  }

  // Single link or no links — just map what's there
  return mapSingleLink(html, linkVar);
}

/**
 * Replace href and inner text of a single link element with menu data attributes.
 */
function mapSingleLink(html, linkVar) {
  // Add data-outpost attributes to the <a> tag
  let result = html.replace(
    /<a\b([^>]*)\bhref\s*=\s*["'][^"']*["']([^>]*)>([\s\S]*?)<\/a>/i,
    '<a$1 data-outpost="url" data-type="link"$2>Menu Item</a>'
  );
  return result;
}

/**
 * Insert a form tag: <outpost-form slug="name" />
 */
export function wrapForm({ formSlug }) {
  return `<outpost-form slug="${sanitizeName(formSlug)}" />`;
}

// ── Helpers ─────────────────────────────────────────────────

/**
 * Sanitize a name/slug for use in template tags.
 * Allows: letters, numbers, underscores, dots, hyphens, @.
 */
function sanitizeName(name) {
  return name.replace(/[^a-zA-Z0-9_.@-]/g, '');
}

/**
 * Build data-outpost attribute string from field name and type.
 */
function buildAttrs(name, type, editable = false) {
  let attrs = `data-outpost="${name}"`;
  if (type && type !== 'text') {
    attrs += ` data-type="${type}"`;
  }
  if (editable) {
    attrs += ` data-editable`;
  }
  return attrs;
}

/**
 * Try to inject attributes into the first HTML opening tag of the selected text.
 * Returns the modified string if successful, or null if no root element found.
 */
function injectAttrsIntoTag(html, attrs) {
  // Match the first opening tag
  const match = html.match(/^(\s*<[a-zA-Z][a-zA-Z0-9]*)([\s>\/])/);
  if (!match) return null;
  const insertPos = match.index + match[1].length;
  return html.slice(0, insertPos) + ' ' + attrs + html.slice(insertPos);
}

/**
 * Detect the leading whitespace of the first line.
 */
function detectIndent(text) {
  const match = text.match(/^([ \t]*)/);
  return match ? match[1] : '';
}

/**
 * Ensure each line of a block has at minimum the given indent.
 * Does not add extra indent if lines already have equal or more.
 */
function indentBlock(text, baseIndent) {
  if (!baseIndent) return text;
  const lines = text.split('\n');
  return lines.map(line => {
    if (line.trim() === '') return line;
    const currentIndent = line.match(/^([ \t]*)/)[1];
    if (currentIndent.length >= baseIndent.length) return line;
    return baseIndent + line.trimStart();
  }).join('\n');
}
