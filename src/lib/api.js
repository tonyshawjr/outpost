/**
 * Outpost CMS — API Client
 */

export class ConflictError extends Error {
  constructor(message, serverData) {
    super(message);
    this.name = 'ConflictError';
    this.serverData = serverData;
  }
}

const config = window.__OUTPOST_CONFIG__ || {};

let csrfToken = config.csrfToken || '';
let apiBase = config.apiUrl || '/outpost/api.php';

// In dev mode, use relative path
if (import.meta.env.DEV) {
  apiBase = '/outpost/api.php';
}

export function setCsrfToken(token) {
  csrfToken = token;
}

export function setApiBase(base) {
  apiBase = base;
}

async function request(action, options = {}) {
  const { method = 'GET', body, params = {} } = options;

  // Build URL with query params
  const url = new URL(apiBase, window.location.origin);
  url.searchParams.set('action', action);
  for (const [key, value] of Object.entries(params)) {
    url.searchParams.set(key, value);
  }

  const headers = {};
  if (method !== 'GET' && !(body instanceof FormData)) {
    headers['Content-Type'] = 'application/json';
  }
  if (['POST', 'PUT', 'DELETE'].includes(method)) {
    headers['X-CSRF-Token'] = csrfToken;
  }

  const fetchOpts = { method, headers, credentials: 'include' };
  if (body) {
    fetchOpts.body = body instanceof FormData ? body : JSON.stringify(body);
  }

  const res = await fetch(url.toString(), fetchOpts);
  const data = await res.json();

  if (res.status === 401 && !action.startsWith('auth/')) {
    window.dispatchEvent(new CustomEvent('outpost:session-expired'));
    throw new Error('Session expired');
  }

  if (!res.ok) {
    if (res.status === 409) {
      throw new ConflictError(
        data.error || 'This content was modified by another user',
        data
      );
    }
    throw new Error(data.error || `Request failed: ${res.status}`);
  }

  return data;
}

// Auth
export const auth = {
  login: (username, password) =>
    request('auth/login', { method: 'POST', body: { username, password } }),
  logout: () =>
    request('auth/logout', { method: 'POST' }),
  me: () =>
    request('auth/me'),
  forgot: (email) =>
    request('auth/forgot', { method: 'POST', body: { email } }),
  reset: (token, password) =>
    request('auth/reset', { method: 'POST', body: { token, password } }),
  totpVerify: (code, totpToken, isBackup = false) =>
    request('auth/totp/verify', { method: 'POST', body: { code, totp_token: totpToken, is_backup: isBackup } }),
  totpSetup: () =>
    request('auth/totp/setup', { method: 'POST', body: {} }),
  totpEnable: (code) =>
    request('auth/totp/enable', { method: 'POST', body: { code } }),
  totpDisable: (password) =>
    request('auth/totp/disable', { method: 'POST', body: { password } }),
  totpBackupCodes: (password) =>
    request('auth/totp/backup-codes', { method: 'POST', body: { password } }),
  totpStatus: () =>
    request('auth/totp/status'),
};

// Pages
export const pages = {
  list: (search = '') =>
    request('pages', { params: search ? { search } : {} }),
  get: (id) =>
    request('pages', { params: { id } }),
  update: (id, data) =>
    request('pages', { method: 'PUT', params: { id }, body: data }),
  delete: (id) =>
    request('pages', { method: 'DELETE', params: { id } }),
};

// Fields
export const fields = {
  list: (pageId) =>
    request('fields', { params: { page_id: pageId } }),
  update: (id, content) =>
    request('fields', { method: 'PUT', params: { id }, body: { content } }),
  bulkUpdate: (fieldUpdates, pageVersions = {}) =>
    request('fields/bulk', { method: 'PUT', body: { fields: fieldUpdates, _page_versions: pageVersions } }),
};

// Global fields (site-wide settings from @field tags in templates)
export const globals = {
  list: () => request('globals'),
};

// Collections
export const collections = {
  list: () =>
    request('collections'),
  get: (id) =>
    request('collections', { params: { id } }),
  create: (data) =>
    request('collections', { method: 'POST', body: data }),
  update: (id, data) =>
    request('collections', { method: 'PUT', params: { id }, body: data }),
  delete: (id) =>
    request('collections', { method: 'DELETE', params: { id } }),
};

// Collection Items
export const items = {
  list: (collectionSlug, status = '') =>
    request('items', { params: { collection: collectionSlug, ...(status ? { status } : {}) } }),
  create: (data) =>
    request('items', { method: 'POST', body: data }),
  update: (id, data) =>
    request('items', { method: 'PUT', params: { id }, body: data }),
  delete: (id) =>
    request('items', { method: 'DELETE', params: { id } }),
  bulkStatus: (ids, status) =>
    request('items/bulk-status', { method: 'PUT', body: { ids, status } }),
  bulkDelete: (ids) =>
    request('items/bulk-delete', { method: 'DELETE', body: { ids } }),
  previewToken: (id) =>
    request('items/preview-token', { method: 'POST', body: { id } }),
};

// Users
export const users = {
  list: () => request('users'),
  get: (id) => request('users', { params: { id } }),
  create: (data) => request('users', { method: 'POST', body: data }),
  update: (id, data) => request('users', { method: 'PUT', params: { id }, body: data }),
  delete: (id) => request('users', { method: 'DELETE', params: { id } }),
  getGrants: (userId) => request('users/grants', { params: { user_id: userId } }),
  setGrants: (userId, collectionIds) => request('users/grants', { method: 'PUT', params: { user_id: userId }, body: { collection_ids: collectionIds } }),
};

// Media
export const media = {
  list: () =>
    request('media'),
  upload: (file) => {
    const formData = new FormData();
    formData.append('file', file);
    formData.append('csrf_token', csrfToken);
    return request('media/upload', { method: 'POST', body: formData });
  },
  update: (id, data) =>
    request('media', { method: 'PUT', params: { id }, body: data }),
  transform: (data) =>
    request('media/transform', { method: 'POST', body: data }),
  delete: (id) =>
    request('media', { method: 'DELETE', params: { id } }),
};

// Stats
export const stats = {
  get: () =>
    request('stats'),
};

// Folders (formerly Taxonomies)
export const folders = {
  list: (collectionId) =>
    request('folders', { params: collectionId ? { collection_id: collectionId } : {} }),
  get: (id) =>
    request('folders', { params: { id } }),
  create: (data) =>
    request('folders', { method: 'POST', body: data }),
  update: (id, data) =>
    request('folders', { method: 'PUT', params: { id }, body: data }),
  delete: (id) =>
    request('folders', { method: 'DELETE', params: { id } }),
};

// Labels (formerly Terms)
export const labels = {
  list: (folderId) =>
    request('labels', { params: { folder_id: folderId } }),
  get: (id) =>
    request('labels', { params: { id } }),
  create: (data) =>
    request('labels', { method: 'POST', body: data }),
  update: (id, data) =>
    request('labels', { method: 'PUT', params: { id }, body: data }),
  delete: (id) =>
    request('labels', { method: 'DELETE', params: { id } }),
};

// Item Labels (formerly Item Terms — label assignments)
export const itemLabels = {
  get: (itemId) =>
    request('item-labels', { params: { item_id: itemId } }),
  set: (itemId, labelIds) =>
    request('item-labels', { method: 'PUT', params: { item_id: itemId }, body: { label_ids: labelIds } }),
};

// Backward-compatible aliases
export { folders as taxonomies };
export { labels as terms };
export { itemLabels as itemTerms };

// Settings
export const settings = {
  get: () =>
    request('settings'),
  update: (data) =>
    request('settings', { method: 'PUT', body: data }),
};

// Updates
export const updates = {
  check: () =>
    request('updates/check'),
  apply: (downloadUrl) =>
    request('updates/apply', { method: 'POST', body: { download_url: downloadUrl } }),
};

// Cache
export const cache = {
  clear: () =>
    request('cache/clear', { method: 'POST' }),
};

// Code Editor
export const code = {
  files: () =>
    request('code/files'),
  read: (path) =>
    request('code/read', { params: { path } }),
  write: (path, content) =>
    request('code/write', { method: 'PUT', body: { path, content } }),
  create: (path, type = 'file') =>
    request('code/create', { method: 'POST', body: { path, type } }),
  rename: (oldPath, newPath) =>
    request('code/rename', { method: 'POST', body: { oldPath, newPath } }),
  delete: (path) =>
    request('code/delete', { method: 'DELETE', body: { path } }),
  search: (q) =>
    request('code/search', { params: { q } }),
  context: () =>
    request('code/context'),
};

// Themes
export const themes = {
  list: () =>
    request('themes'),
  get: (slug) =>
    request('themes', { params: { slug } }),
  activate: (slug) =>
    request('themes/activate', { method: 'PUT', body: { slug } }),
  duplicate: (source, name) =>
    request('themes/duplicate', { method: 'POST', body: { source, name } }),
  delete: (slug) =>
    request('themes', { method: 'DELETE', params: { slug } }),
};

// Public Content API (read-only, used by Template Reference)
export const content = {
  schema: () =>
    request('content/schema'),
  syntax: () =>
    request('content/syntax'),
  pages: () =>
    request('content/pages'),
  page: (path) =>
    request('content/pages', { params: { path } }),
  items: (slug, opts = {}) =>
    request('content/items', { params: { collection: slug, ...opts } }),
  globals: () =>
    request('content/globals'),
};

// Forms
export const forms = {
  list: () =>
    request('forms'),
  submissions: (params = {}) =>
    request('forms/submissions', { params }),
  markRead: (id) =>
    request('forms/submissions', { method: 'PUT', params: { id }, body: {} }),
  delete: (id) =>
    request('forms/submissions', { method: 'DELETE', params: { id } }),
  star: (id) =>
    request('forms/submissions/star', { method: 'PUT', params: { id }, body: {} }),
  setStatus: (id, status) =>
    request('forms/submissions/status', { method: 'PUT', params: { id }, body: { status } }),
  setNotes: (id, notes) =>
    request('forms/submissions/notes', { method: 'PUT', params: { id }, body: { notes } }),
  bulk: (ids, action) =>
    request('forms/submissions/bulk', { method: 'POST', body: { ids, action } }),
  testSmtp: (config) =>
    request('forms/test-smtp', { method: 'POST', body: config }),
  getConfig: (formName) =>
    request('forms/config', { params: { form: formName } }),
  setConfig: (formName, notifyEmail) =>
    request('forms/config', { method: 'PUT', body: { form_name: formName, notify_email: notifyEmail } }),
  exportUrl: (formName = '') => {
    const url = new URL(apiBase, window.location.origin);
    url.searchParams.set('action', 'forms/export');
    if (formName) url.searchParams.set('form', formName);
    return url.toString();
  },
};

// Form Builder
export const formBuilder = {
  list: () =>
    request('forms/builder'),
  get: (id) =>
    request('forms/builder', { params: { id } }),
  create: (data) =>
    request('forms/builder', { method: 'POST', body: data }),
  update: (id, data) =>
    request('forms/builder', { method: 'PUT', params: { id }, body: data }),
  delete: (id) =>
    request('forms/builder', { method: 'DELETE', params: { id } }),
  duplicate: (id) =>
    request('forms/builder/duplicate', { method: 'POST', params: { id }, body: {} }),
};

// Navigation Menus
export const navigation = {
  list: () =>
    request('menus'),
  get: (id) =>
    request('menus', { params: { id } }),
  create: (data) =>
    request('menus', { method: 'POST', body: data }),
  update: (id, data) =>
    request('menus', { method: 'PUT', params: { id }, body: data }),
  delete: (id) =>
    request('menus', { method: 'DELETE', params: { id } }),
};

// Members (admin management)
export const members = {
  list: () =>
    request('members'),
  update: (id, data) =>
    request('members', { method: 'PUT', params: { id }, body: data }),
  delete: (id) =>
    request('members', { method: 'DELETE', params: { id } }),
};

// Sync (Outpost Builder integration — admin+ only)
export const sync = {
  key: () =>
    request('sync/key'),
  regenerateKey: () =>
    request('sync/key/regenerate', { method: 'POST', body: {} }),
};

// API Keys (headless auth — admin+ only)
export const apikeys = {
  list: () => request('apikeys'),
  create: (data) => request('apikeys', { method: 'POST', body: data }),
  revoke: (id) => request('apikeys', { method: 'DELETE', params: { id } }),
};

// Webhooks (admin+ only)
export const webhooks = {
  list: () => request('webhooks'),
  get: (id) => request('webhooks', { params: { id } }),
  create: (data) => request('webhooks', { method: 'POST', body: data }),
  update: (id, data) => request('webhooks', { method: 'PUT', params: { id }, body: data }),
  delete: (id) => request('webhooks', { method: 'DELETE', params: { id } }),
  regenerateSecret: (id) => request('webhooks/regenerate-secret', { method: 'POST', params: { id }, body: {} }),
  deliveries: (id, limit = 50) => request('webhooks/deliveries', { params: { id, limit } }),
  test: (id) => request('webhooks/test', { method: 'POST', params: { id }, body: {} }),
};

// Cron key (scheduled publishing — admin+ only)
export const cron = {
  key: () =>
    request('cron/key'),
  regenerateKey: () =>
    request('cron/key/regenerate', { method: 'POST', body: {} }),
};

// Dashboard
export const dashboard = {
  stats: (period = '30days') =>
    request('dashboard/stats', { params: { period } }),
  activity: () =>
    request('dashboard/activity'),
};

// Analytics
// Import
export const importApi = {
  wordpress: (file, options = {}) => {
    const formData = new FormData();
    formData.append('file', file);
    for (const [k, v] of Object.entries(options)) {
      formData.append(k, String(v));
    }
    return request('import/wordpress', { method: 'POST', body: formData });
  },
};

export const analytics = {
  traffic: (period = '30days') =>
    request('dashboard/analytics', { params: { period } }),
  seo: () =>
    request('dashboard/seo'),
  content: (period = '30days') =>
    request('dashboard/content', { params: { period } }),
  members: (period = '30days') =>
    request('dashboard/members', { params: { period } }),
  events: (period = '30days') =>
    request('dashboard/events', { params: { period } }),
  eventDetail: (name, period = '30days') =>
    request('dashboard/events/detail', { params: { name, period } }),
  goals: (period = '30days') =>
    request('dashboard/goals', { params: { period } }),
};

export const goals = {
  list: () => request('goals'),
  create: (data) => request('goals', { method: 'POST', body: data }),
  update: (id, data) => request('goals', { method: 'PUT', params: { id }, body: data }),
  delete: (id) => request('goals', { method: 'DELETE', params: { id } }),
};

export const search = {
  content: (q) => request('search/content', { params: { q } }),
};

// Channels
export const channels = {
  list: () => request('channels'),
  get: (id) => request('channels', { params: { id } }),
  create: (data) => request('channels', { method: 'POST', body: data }),
  update: (id, data) => request('channels', { method: 'PUT', params: { id }, body: data }),
  delete: (id) => request('channels', { method: 'DELETE', params: { id } }),
  sync: (id) => request('channels/sync', { method: 'POST', params: { id }, body: {} }),
  syncLog: (id) => request('channels/sync-log', { params: { id } }),
  discover: (config) => request('channels/discover', { method: 'POST', body: config }),
  items: (id, page = 1) => request('channels/items', { params: { id, page } }),
};

// Revisions
export const revisions = {
  list: (entityType, entityId) =>
    request('revisions', { params: { entity_type: entityType, entity_id: entityId } }),
  diff: (entityType, entityId, revisionId) =>
    request('revisions/diff', { params: { entity_type: entityType, entity_id: entityId, revision_id: revisionId } }),
  restore: (entityType, entityId, revisionId) =>
    request('revisions/restore', { method: 'POST', body: { entity_type: entityType, entity_id: entityId, revision_id: revisionId } }),
};
