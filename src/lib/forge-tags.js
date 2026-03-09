/**
 * Forge — Pure tag wrapping functions for Outpost Liquid templates.
 * Each function takes a config object and returns the output string to insert.
 */

/**
 * Wrap selection as an editable field: {{ field }}, {{ field | filter }}, {{ @global }}, or wrapping default.
 */
export function wrapEditable({ fieldName, type = 'text', scope = 'page', useDefault = false, selectedText = '' }) {
  const prefix = scope === 'global' ? '@' : '';
  const filter = getFilter(type);
  const name = `${prefix}${sanitizeName(fieldName)}`;

  // Wrapping default: {{ field }}Default{{ /field }}
  if (useDefault && selectedText) {
    return `{{ ${name}${filter} }}${selectedText}{{ /${name} }}`;
  }

  return `{{ ${name}${filter} }}`;
}

/**
 * Wrap selection in a collection loop: {% for item in collection.slug %}...{% endfor %}
 */
export function wrapCollectionLoop({ collectionSlug, itemVar = 'item', limit = '', orderby = '', selectedText = '' }) {
  const slug = sanitizeName(collectionSlug);
  const varName = sanitizeName(itemVar) || 'item';
  let options = '';
  if (limit) options += ` limit:${sanitizeName(limit)}`;
  if (orderby) options += ` orderby:${sanitizeName(orderby)}`;

  const inner = selectedText || `  {{ ${varName}.title }}`;
  const indent = detectIndent(selectedText || inner);

  return `{% for ${varName} in collection.${slug}${options} %}\n${indentBlock(inner, indent)}\n${indent}{% endfor %}`;
}

/**
 * Wrap selection in a conditional: {% if expression %}...{% endif %}
 */
export function wrapConditional({ expression, operator = 'truthy', value = '', selectedText = '' }) {
  let condition = sanitizeName(expression);
  const escapedValue = value.replace(/"/g, '\\"');
  if (operator === '==' && escapedValue) condition += ` == "${escapedValue}"`;
  if (operator === '!=' && escapedValue) condition += ` != "${escapedValue}"`;

  const inner = selectedText || '  ';
  const indent = detectIndent(selectedText || inner);

  return `{% if ${condition} %}\n${indentBlock(inner, indent)}\n${indent}{% endif %}`;
}

/**
 * Return an include tag for a partial.
 */
export function wrapPartialInclude(partialName) {
  return `{% include '${sanitizeName(partialName)}' %}`;
}

/**
 * Wrap selection in a meta tag: {{ meta.title }}Default{{ /meta.title }}
 */
export function wrapMeta({ metaType = 'title', selectedText = '' }) {
  const tag = metaType === 'description' ? 'meta.description' : 'meta.title';
  const inner = selectedText || 'Default';
  return `{{ ${tag} }}${inner}{{ /${tag} }}`;
}

/**
 * Wrap selection in a menu loop: {% for link in menu.slug %}...{% endfor %}
 * Replaces static <a> links with dynamic {{ link.url }} and {{ link.label }}.
 */
export function wrapMenuLoop({ menuSlug, linkVar = 'link', selectedText = '', applyMapping = false }) {
  const slug = sanitizeName(menuSlug);
  const varName = sanitizeName(linkVar) || 'link';
  let inner = selectedText || `  <a href="{{ ${varName}.url }}">{{ ${varName}.label }}</a>`;

  if (applyMapping && selectedText) {
    inner = applyMenuMappings(selectedText, varName);
  }

  const indent = detectIndent(inner);
  return `{% for ${varName} in menu.${slug} %}\n${indentBlock(inner, indent)}\n${indent}{% endfor %}`;
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
 * Replace href and inner text of a single link element with menu tags.
 */
function mapSingleLink(html, linkVar) {
  // Replace href value
  let result = html.replace(
    /(<a\b[^>]*href\s*=\s*["'])[^"']*(["'])/i,
    `$1{{ ${linkVar}.url }}$2`
  );
  // Replace inner text of the <a> tag
  result = result.replace(
    /(<a\b[^>]*>)([\s\S]*?)(<\/a>)/i,
    `$1{{ ${linkVar}.label }}$3`
  );
  return result;
}

/**
 * Insert a form tag: {% form 'slug' %}
 */
export function wrapForm({ formSlug }) {
  return `{% form '${sanitizeName(formSlug)}' %}`;
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
 * Map field type to Liquid filter string.
 */
function getFilter(type) {
  const filters = {
    text: '',
    richtext: ' | raw',
    image: ' | image',
    link: ' | link',
    textarea: ' | textarea',
    select: ' | select',
    color: ' | color',
    number: ' | number',
    date: ' | date',
    toggle: ' | toggle',
    focal: ' | focal',
  };
  return filters[type] ?? '';
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
