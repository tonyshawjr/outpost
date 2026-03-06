/**
 * Inline field validation — returns error string or '' (empty = valid).
 */

export function required(value, label = 'This field') {
  return (value ?? '').toString().trim() ? '' : `${label} is required`;
}

export function minLength(value, min, label = 'This field') {
  const v = (value ?? '').toString();
  if (!v) return ''; // don't double-report with required
  return v.length >= min ? '' : `${label} must be at least ${min} characters`;
}

export function email(value) {
  const v = (value ?? '').toString().trim();
  if (!v) return '';
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v) ? '' : 'Enter a valid email address';
}

export function slug(value, label = 'Slug') {
  const v = (value ?? '').toString().trim();
  if (!v) return '';
  return /^[a-z0-9]+(?:[_-][a-z0-9]+)*$/.test(v)
    ? ''
    : `${label} must be lowercase letters, numbers, hyphens, or underscores`;
}

export function match(a, b, label = 'Passwords') {
  if (!a && !b) return '';
  return a === b ? '' : `${label} do not match`;
}

/**
 * Run a set of field rules and return an errors object.
 * rules: { fieldName: errorString | '' }
 * Returns only non-empty entries.
 */
export function validate(rules) {
  const errors = {};
  for (const [key, msg] of Object.entries(rules)) {
    if (msg) errors[key] = msg;
  }
  return errors;
}

/**
 * Check if errors object has any entries.
 */
export function hasErrors(errors) {
  return Object.keys(errors).length > 0;
}
