/**
 * Forge — Smart HTML detection engine
 * Analyzes selected HTML and suggests the most likely tag type.
 */

const DEFAULT_ORDER = ['editable', 'loop', 'menu', 'conditional', 'partial', 'meta', 'form'];

/**
 * Analyze selected HTML text and return a suggested action, field type, and reordered menu.
 * @param {string} text - The selected HTML text
 * @returns {{ suggestedAction: string, suggestedType: string|null, menuOrder: string[] }}
 */
export function detectForgeIntent(text) {
  const trimmed = text.trim();
  const lower = trimmed.toLowerCase();

  // <form> → Form
  if (/^<form[\s>]/i.test(trimmed)) {
    return result('form', null);
  }

  // <title> or <meta name="description" → Meta Tag
  if (/^<title[\s>]/i.test(trimmed) || /<meta\s[^>]*name\s*=\s*["']description/i.test(trimmed)) {
    const metaType = /^<title/i.test(trimmed) ? 'title' : 'description';
    return result('meta', metaType);
  }

  // <ul> or <ol> with multiple <a> links → Menu Loop
  if (/^<(ul|ol)[\s>]/i.test(trimmed) && (trimmed.match(/<a\b/gi) || []).length >= 2) {
    return result('menu', null);
  }

  // <nav> with multiple links → suggest partial (with menu hint), single link → partial
  if (/^<nav[\s>]/i.test(trimmed)) {
    const linkCount = (trimmed.match(/<a\b/gi) || []).length;
    if (linkCount >= 2) return result('partial', null); // Smart partial will offer menu conversion
    return result('partial', null);
  }

  // <header>, <footer> → Extract Partial
  if (/^<(header|footer)[\s>]/i.test(trimmed)) {
    return result('partial', null);
  }

  // <img>, <picture>, <figure>, or src="..." with image extension → Image
  if (/^<(img|picture|figure)[\s>\/]/i.test(trimmed) || /src\s*=\s*["'][^"']*\.(jpg|jpeg|png|gif|webp|svg|avif)/i.test(trimmed)) {
    return result('editable', 'image');
  }

  // <a href="..."> → Link
  if (/^<a\s[^>]*href\s*=/i.test(trimmed)) {
    return result('editable', 'link');
  }

  // <h1>–<h6> with content → Text
  if (/^<h[1-6][\s>]/i.test(trimmed)) {
    return result('editable', 'text');
  }

  // Check if it contains HTML tags
  const hasHtmlTags = /<[a-z][^>]*>/i.test(trimmed);

  // Multiple repeated sibling elements (e.g., multiple <div>, <li>, <article>) → Collection Loop
  if (hasHtmlTags && hasRepeatedSiblings(trimmed)) {
    return result('loop', null);
  }

  // <section>, <article>, large <div> blocks → Conditional
  if (/^<(section|article)[\s>]/i.test(trimmed) || (/^<div[\s>]/i.test(trimmed) && trimmed.length > 200)) {
    return result('conditional', null);
  }

  // Rich HTML content (has child tags) → Richtext
  if (hasHtmlTags) {
    return result('editable', 'richtext');
  }

  // Plain text — short vs long
  if (trimmed.length === 0) {
    return result('editable', 'text');
  }

  if (trimmed.length < 100 && !trimmed.includes('\n')) {
    return result('editable', 'text');
  }

  return result('editable', 'textarea');
}

/**
 * Build the result object with reordered menu.
 */
function result(action, type) {
  const menuOrder = [action, ...DEFAULT_ORDER.filter(a => a !== action)];
  return { suggestedAction: action, suggestedType: type, menuOrder };
}

/**
 * Detect mappable content elements inside an HTML block for the loop field mapper.
 * Returns an array of detected fields with their type, a display snippet, and
 * enough context to perform find-and-replace in the original HTML.
 *
 * @param {string} html - The selected HTML
 * @returns {Array<{ id: number, snippet: string, type: string, match: string, context: string }>}
 */
export function detectLoopFields(html) {
  const fields = [];
  let id = 0;
  const seen = new Set(); // avoid duplicate matches

  // <img src="..."> → image
  const imgRe = /<img\b[^>]*\bsrc\s*=\s*["']([^"']+)["'][^>]*>/gi;
  let m;
  while ((m = imgRe.exec(html)) !== null) {
    if (seen.has(m[1])) continue;
    seen.add(m[1]);
    const alt = m[0].match(/alt\s*=\s*["']([^"']+)/i);
    fields.push({
      id: id++,
      snippet: alt ? alt[1] : m[1].split('/').pop().split('?')[0].slice(0, 40),
      type: 'image',
      match: m[1],       // the src value to replace
      context: 'src',
    });
  }

  // <a href="...">text</a> → link
  const aRe = /<a\b[^>]*\bhref\s*=\s*["']([^"']+)["'][^>]*>([\s\S]*?)<\/a>/gi;
  while ((m = aRe.exec(html)) !== null) {
    if (seen.has(m[1])) continue;
    seen.add(m[1]);
    const innerText = m[2].replace(/<[^>]+>/g, '').trim();
    fields.push({
      id: id++,
      snippet: innerText || m[1].slice(0, 40),
      type: 'link',
      match: m[1],       // href value to replace
      context: 'href',
    });
  }

  // <h1>–<h6> → text
  const hRe = /<(h[1-6])\b[^>]*>([\s\S]*?)<\/\1>/gi;
  while ((m = hRe.exec(html)) !== null) {
    const inner = m[2].trim();
    const plainText = inner.replace(/<[^>]+>/g, '').trim();
    if (!plainText || seen.has(inner)) continue;
    seen.add(inner);
    fields.push({
      id: id++,
      snippet: plainText.slice(0, 50),
      type: 'text',
      match: inner,       // inner HTML to replace
      context: 'inner',
    });
  }

  // <p> → text or richtext
  const pRe = /<p\b[^>]*>([\s\S]*?)<\/p>/gi;
  while ((m = pRe.exec(html)) !== null) {
    const inner = m[1].trim();
    if (!inner || seen.has(inner)) continue;
    const plainText = inner.replace(/<[^>]+>/g, '').trim();
    if (plainText.length < 3) continue;
    seen.add(inner);
    const hasHtml = /<[a-z]/i.test(inner);
    fields.push({
      id: id++,
      snippet: plainText.slice(0, 50),
      type: hasHtml ? 'richtext' : 'text',
      match: inner,
      context: 'inner',
    });
  }

  // <span>, <time>, <small> with short text → text
  const inlineRe = /<(span|time|small|strong|em)\b[^>]*>([^<]{3,80})<\/\1>/gi;
  while ((m = inlineRe.exec(html)) !== null) {
    const inner = m[2].trim();
    if (!inner || seen.has(inner)) continue;
    seen.add(inner);
    fields.push({
      id: id++,
      snippet: inner.slice(0, 50),
      type: 'text',
      match: inner,
      context: 'inner',
    });
  }

  return fields;
}

/**
 * Given detected loop fields and user-provided mappings, apply find-and-replace
 * on the HTML to insert template tags.
 *
 * @param {string} html - The original HTML
 * @param {Array<{ match: string, context: string, field: string, fieldType: string }>} mappings
 * @param {string} itemVar - The loop item variable (e.g., 'item')
 * @returns {string} - The HTML with template tags inserted
 */
export function applyLoopMappings(html, mappings, itemVar) {
  let result = html;
  for (const { match, context, field, fieldType } of mappings) {
    if (!field) continue; // unmapped — skip

    let replacement;
    if (context === 'src') {
      replacement = `{{ ${itemVar}.${field} | image }}`;
    } else if (context === 'href') {
      replacement = `{{ ${itemVar}.url }}`;
    } else if (context === 'inner') {
      const filter = fieldType === 'richtext' ? ' | raw' : '';
      replacement = `{{ ${itemVar}.${field}${filter} }}`;
    } else {
      replacement = `{{ ${itemVar}.${field} }}`;
    }

    result = result.replace(match, replacement);
  }
  return result;
}

/**
 * Detect repeated sibling HTML elements (e.g., 3+ <div class="card"> blocks).
 * Looks for 2+ top-level elements with the same tag name.
 */
function hasRepeatedSiblings(html) {
  // Match top-level opening tags (not nested ones)
  const topTags = [];
  const re = /^[ \t]*<([a-z][a-z0-9]*)/gim;
  let m;
  let depth = 0;

  // Simple heuristic: count opening tags at the start of lines
  const lines = html.split('\n');
  for (const line of lines) {
    const trimLine = line.trimStart();
    if (/^<\//.test(trimLine)) {
      depth = Math.max(0, depth - 1);
    } else if (/^<[a-z]/i.test(trimLine)) {
      if (depth === 0) {
        const match = trimLine.match(/^<([a-z][a-z0-9]*)/i);
        if (match) topTags.push(match[1].toLowerCase());
      }
      // Self-closing tags don't increase depth
      if (!/\/\s*>/.test(trimLine)) {
        depth++;
      }
    }
  }

  if (topTags.length < 2) return false;

  // Check if most top-level tags are the same
  const counts = {};
  topTags.forEach(t => { counts[t] = (counts[t] || 0) + 1; });
  const maxCount = Math.max(...Object.values(counts));
  return maxCount >= 2;
}
