/**
 * Outpost CMS — Utilities
 */

export function formatDate(dateStr) {
  if (!dateStr) return '';
  const d = new Date(dateStr);
  return d.toLocaleDateString('en-US', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
  });
}

export function timeAgo(dateStr) {
  if (!dateStr) return '';
  const d = new Date(dateStr.replace ? dateStr.replace(' ', 'T') : dateStr);
  const now = new Date();
  const diff = Math.floor((now - d) / 1000);

  if (diff < 60) return 'just now';
  if (diff < 3600) return `${Math.floor(diff / 60)}m ago`;
  if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`;
  if (diff < 604800) return `${Math.floor(diff / 86400)}d ago`;
  return formatDate(dateStr);
}

// Date only — no time. Shows relative for recent, date for older.
export function formatDateOnly(dateStr) {
  if (!dateStr) return '';
  const d = new Date(dateStr.replace ? dateStr.replace(' ', 'T') : dateStr);
  if (isNaN(d)) return '';
  const diff = Math.floor((new Date() - d) / 1000);
  if (diff < 60) return 'just now';
  if (diff < 3600) return `${Math.floor(diff / 60)}m ago`;
  if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`;
  if (diff < 604800) return `${Math.floor(diff / 86400)}d ago`;
  return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}

export function slugify(text) {
  return text
    .toLowerCase()
    .trim()
    .replace(/[^\w\s-]/g, '')
    .replace(/[\s_]+/g, '-')
    .replace(/-+/g, '-')
    .replace(/^-+|-+$/g, '');
}

export function debounce(fn, delay = 300) {
  let timer;
  return (...args) => {
    clearTimeout(timer);
    timer = setTimeout(() => fn(...args), delay);
  };
}

export function truncate(str, len = 80) {
  if (!str || str.length <= len) return str || '';
  return str.slice(0, len) + '...';
}

export function humanFileSize(bytes) {
  if (bytes === 0) return '0 B';
  const k = 1024;
  const sizes = ['B', 'KB', 'MB', 'GB'];
  const i = Math.floor(Math.log(bytes) / Math.log(k));
  return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
}

export function fieldTypeLabel(type) {
  const labels = {
    text: 'Text',
    textarea: 'Textarea',
    richtext: 'Rich Text',
    image: 'Image',
    link: 'Link',
    select: 'Select',
    toggle: 'Toggle',
    color: 'Color',
    number: 'Number',
    date: 'Date',
    meta_title: 'Meta Title',
    meta_description: 'Meta Description',
    repeater: 'Repeater',
  };
  return labels[type] || type;
}
