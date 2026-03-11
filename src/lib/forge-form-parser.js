/**
 * Forge — HTML form parser for auto-creating Outpost forms from <form> markup.
 */

/**
 * Returns true if the text looks like a <form> element.
 */
export function isFormHtml(text) {
  return /^\s*<form[\s>]/i.test(text);
}

/**
 * Convert a form name to a URL-safe slug.
 */
export function slugify(name) {
  return name
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-|-$/g, '')
    || 'form';
}

/**
 * Parse an HTML <form> string and return { name, fields[], submitLabel }.
 * Uses DOMParser for robust, browser-native parsing.
 */
export function parseFormHtml(html) {
  const doc = new DOMParser().parseFromString(html, 'text/html');
  const form = doc.querySelector('form');
  if (!form) return null;

  const name = deriveFormName(form);
  const submitLabel = deriveSubmitLabel(form);
  const fields = extractFields(form);

  return { name, fields, submitLabel };
}

// ── Internal helpers ────────────────────────────────────────

function deriveFormName(form) {
  if (form.id) return humanize(form.id);
  if (form.getAttribute('name')) return humanize(form.getAttribute('name'));

  const heading = form.querySelector('h1, h2, h3, h4, h5, h6, legend');
  if (heading?.textContent?.trim()) return heading.textContent.trim();

  const action = form.getAttribute('action');
  if (action) {
    const basename = action.split('/').filter(Boolean).pop();
    if (basename && !basename.includes('.')) return humanize(basename);
  }

  return 'New Form';
}

function deriveSubmitLabel(form) {
  const btn = form.querySelector('button[type="submit"], input[type="submit"]');
  if (btn) {
    if (btn.tagName === 'INPUT') return btn.value || 'Submit';
    return btn.textContent?.trim() || 'Submit';
  }
  // Fallback: any <button> without explicit type (defaults to submit)
  const anyBtn = form.querySelector('button:not([type="button"]):not([type="reset"])');
  if (anyBtn) return anyBtn.textContent?.trim() || 'Submit';
  return 'Submit';
}

function extractFields(form) {
  const fields = [];
  const usedNames = new Set();
  const groupedRadios = new Map();   // name → { element, labels[] }
  const groupedCheckboxes = new Map();

  const elements = form.querySelectorAll('input, textarea, select');

  for (const el of elements) {
    const tag = el.tagName.toLowerCase();
    const inputType = (el.getAttribute('type') || 'text').toLowerCase();

    // Skip non-data inputs
    if (tag === 'input' && ['submit', 'button', 'reset', 'image'].includes(inputType)) continue;

    const elName = el.getAttribute('name') || '';

    // Group radios by name
    if (tag === 'input' && inputType === 'radio') {
      if (!groupedRadios.has(elName)) {
        groupedRadios.set(elName, { element: el, choices: [] });
      }
      const choiceLabel = findLabel(el, form) || el.value || '';
      groupedRadios.get(elName).choices.push(choiceLabel);
      continue;
    }

    // Group checkboxes by name
    if (tag === 'input' && inputType === 'checkbox') {
      if (!groupedCheckboxes.has(elName)) {
        groupedCheckboxes.set(elName, { element: el, choices: [] });
      }
      const choiceLabel = findLabel(el, form) || el.value || '';
      groupedCheckboxes.get(elName).choices.push(choiceLabel);
      continue;
    }

    const field = buildField(el, tag, inputType, form, usedNames);
    if (field) fields.push(field);
  }

  // Add grouped radios
  for (const [name, group] of groupedRadios) {
    const label = findGroupLabel(group.element, form) || humanize(name) || 'Radio';
    const fieldName = uniqueName(slugifyField(name || label), usedNames);
    fields.push({
      id: generateId(),
      type: 'radio',
      label,
      name: fieldName,
      placeholder: '',
      required: group.element.required || false,
      choices: group.choices.filter(Boolean),
      settings: {},
    });
  }

  // Add grouped checkboxes
  for (const [name, group] of groupedCheckboxes) {
    const label = findGroupLabel(group.element, form) || humanize(name) || 'Checkbox';
    const fieldName = uniqueName(slugifyField(name || label), usedNames);
    // Single checkbox with no peers = toggle
    if (group.choices.length <= 1) {
      fields.push({
        id: generateId(),
        type: 'checkbox',
        label: group.choices[0] || label,
        name: fieldName,
        placeholder: '',
        required: group.element.required || false,
        choices: [],
        settings: {},
      });
    } else {
      fields.push({
        id: generateId(),
        type: 'checkbox',
        label,
        name: fieldName,
        placeholder: '',
        required: group.element.required || false,
        choices: group.choices.filter(Boolean),
        settings: {},
      });
    }
  }

  return fields;
}

function buildField(el, tag, inputType, form, usedNames) {
  const type = mapType(tag, inputType);
  const label = findLabel(el, form) || humanize(el.getAttribute('name') || type);
  const rawName = el.getAttribute('name') || slugifyField(label);
  const name = uniqueName(slugifyField(rawName), usedNames);

  const field = {
    id: generateId(),
    type,
    label,
    name,
    placeholder: el.getAttribute('placeholder') || '',
    required: el.required || el.hasAttribute('required'),
    choices: [],
    settings: {},
  };

  // Extract <select> choices
  if (tag === 'select') {
    const options = el.querySelectorAll('option');
    field.choices = Array.from(options)
      .map(o => o.textContent?.trim() || o.value)
      .filter(Boolean);
    // Remove empty first option (common "Choose..." placeholder)
    if (field.choices.length && !el.querySelector('option')?.value) {
      field.choices.shift();
    }
  }

  return field;
}

const TYPE_MAP = {
  email: 'email',
  tel: 'phone',
  url: 'url',
  number: 'number',
  date: 'date',
  time: 'time',
  hidden: 'hidden',
  color: 'text',
  search: 'text',
  password: 'text',
  text: 'text',
};

function mapType(tag, inputType) {
  if (tag === 'textarea') return 'textarea';
  if (tag === 'select') return 'select';
  return TYPE_MAP[inputType] || 'text';
}

function findLabel(el, form) {
  // 1. <label for="id">
  const id = el.id;
  if (id) {
    const lbl = form.querySelector(`label[for="${CSS.escape(id)}"]`);
    if (lbl?.textContent?.trim()) return lbl.textContent.trim();
  }

  // 2. Wrapping <label>
  const parent = el.closest('label');
  if (parent) {
    // Get label text excluding the input element itself
    const clone = parent.cloneNode(true);
    clone.querySelectorAll('input, select, textarea').forEach(c => c.remove());
    const text = clone.textContent?.trim();
    if (text) return text;
  }

  // 3. aria-label
  const ariaLabel = el.getAttribute('aria-label');
  if (ariaLabel) return ariaLabel;

  // 4. placeholder
  const placeholder = el.getAttribute('placeholder');
  if (placeholder) return placeholder;

  // 5. Humanized name attr
  const name = el.getAttribute('name');
  if (name) return humanize(name);

  return '';
}

function findGroupLabel(el, form) {
  // For radio/checkbox groups, check if there's a fieldset > legend
  const fieldset = el.closest('fieldset');
  if (fieldset) {
    const legend = fieldset.querySelector('legend');
    if (legend?.textContent?.trim()) return legend.textContent.trim();
  }
  return '';
}

function humanize(str) {
  return str
    .replace(/[-_]+/g, ' ')
    .replace(/([a-z])([A-Z])/g, '$1 $2')
    .replace(/\b\w/g, c => c.toUpperCase())
    .trim();
}

function slugifyField(text) {
  return text.toLowerCase().replace(/[^a-z0-9_]/g, '_').replace(/_+/g, '_').replace(/^_|_$/g, '');
}

function uniqueName(name, usedNames) {
  if (!name) name = 'field';
  let candidate = name;
  let n = 1;
  while (usedNames.has(candidate)) {
    n++;
    candidate = `${name}_${n}`;
  }
  usedNames.add(candidate);
  return candidate;
}

function generateId() {
  return 'f_' + Math.random().toString(36).substring(2, 9);
}
