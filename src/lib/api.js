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
  list: (collectionSlug, status = '', labelId = '') =>
    request('items', { params: { collection: collectionSlug, ...(status ? { status } : {}), ...(labelId ? { label_id: labelId } : {}) } }),
  labelsWithCounts: (collectionSlug) =>
    request('items/labels-with-counts', { params: { collection: collectionSlug } }),
  bulkAssignLabels: (itemIds, labelId, action) =>
    request('items/bulk-labels', { method: 'POST', body: { item_ids: itemIds, label_id: labelId, action } }),
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
  bulkSchedule: (ids, scheduled_at) =>
    request('items/bulk-schedule', { method: 'PUT', body: { ids, scheduled_at } }),
  approve: (ids) =>
    request('items/approve', { method: 'PUT', body: { ids } }),
  reject: (ids) =>
    request('items/reject', { method: 'PUT', body: { ids } }),
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
  getMediaFolderGrants: (userId) => request('users/media-folder-grants', { params: { user_id: userId } }),
  setMediaFolderGrants: (userId, folderIds) => request('users/media-folder-grants', { method: 'PUT', params: { user_id: userId }, body: { folder_ids: folderIds } }),
};

// Media
export const media = {
  list: (folderId) =>
    request('media', { params: folderId != null ? { folder_id: folderId } : {} }),
  upload: (file, folderId) => {
    const formData = new FormData();
    formData.append('file', file);
    formData.append('csrf_token', csrfToken);
    if (folderId != null) formData.append('folder_id', folderId);
    return request('media/upload', { method: 'POST', body: formData });
  },
  update: (id, data) =>
    request('media', { method: 'PUT', params: { id }, body: data }),
  transform: (data) =>
    request('media/transform', { method: 'POST', body: data }),
  delete: (id) =>
    request('media', { method: 'DELETE', params: { id } }),
  bulkDelete: (ids) =>
    request('media/bulk-delete', { method: 'DELETE', body: { ids } }),
  moveToFolder: (ids, folderId, action = 'set') =>
    request('media/move', { method: 'PUT', body: { ids, folder_id: folderId, action } }),
  getFolders: (mediaId) =>
    request('media/folders', { params: { media_id: mediaId } }),
  assignFolders: (mediaId, folderIds) =>
    request('media/assign-folders', { method: 'PUT', body: { media_id: mediaId, folder_ids: folderIds } }),
};

// Media Folders
export const mediaFolders = {
  list: () =>
    request('media-folders'),
  create: (data) =>
    request('media-folders', { method: 'POST', body: data }),
  bulkCreate: (names, parentId = null) =>
    request('media-folders/bulk', { method: 'POST', body: { names, parent_id: parentId } }),
  update: (id, data) =>
    request('media-folders', { method: 'PUT', params: { id }, body: data }),
  delete: (id) =>
    request('media-folders', { method: 'DELETE', params: { id } }),
};

// CSRF & API base exports (for XHR uploads and streaming fetch)
export function getCsrfToken() { return csrfToken; }
export function getApiBase() { return apiBase; }

// Ranger AI Assistant
export const ranger = {
  conversations: () => request('ranger/conversations'),
  conversation: (id) => request('ranger/conversations', { params: { id } }),
  deleteConversation: (id) => request('ranger/conversations', { method: 'DELETE', params: { id } }),
  getSettings: () => request('ranger/settings'),
  updateSettings: (data) => request('ranger/settings', { method: 'PUT', body: data }),
};

// Stats
export const stats = {
  get: () =>
    request('stats'),
};

// Calendar
export const calendar = {
  get: (start, end, collection) =>
    request('calendar', { params: { start, end, ...(collection && { collection }) } }),
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

export const featureFlags = {
  get: () => request('settings/features'),
  update: (flags) => request('settings/features', { method: 'PUT', body: { feature_flags: flags } }),
};

// Updates
export const updates = {
  check: (force = false) =>
    request('updates/check', force ? { params: { force: '1' } } : {}),
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
    request('code/write', { method: 'POST', body: { path, content: btoa(unescape(encodeURIComponent(content))), encoding: 'base64' } }),
  create: (path, type = 'file', content = '') =>
    request('code/create', { method: 'POST', body: { path, type, content } }),
  rename: (oldPath, newPath) =>
    request('code/rename', { method: 'POST', body: { oldPath, newPath } }),
  delete: (path) =>
    request('code/delete', { method: 'DELETE', body: { path } }),
  search: (q) =>
    request('code/search', { params: { q } }),
  context: () =>
    request('code/context'),
  reset: (folder) =>
    request('code/reset', { method: 'POST', body: { folder } }),
  assets: (theme) =>
    request('code/assets', { params: { theme } }),
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
  create: (name) =>
    request('themes/create', { method: 'POST', body: { name } }),
  upload: (file) => {
    const formData = new FormData();
    formData.append('theme', file);
    formData.append('csrf_token', csrfToken);
    return request('themes/upload', { method: 'POST', body: formData });
  },
  exportUrl: (slug) =>
    `${apiBase}?action=themes/export&slug=${encodeURIComponent(slug)}`,
  delete: (slug) =>
    request('themes', { method: 'DELETE', params: { slug } }),
};

// Theme Customizer
export const customizer = {
  get: () =>
    request('customizer'),
  save: (values) =>
    request('customizer', { method: 'PUT', body: values }),
  reset: () =>
    request('customizer/reset', { method: 'POST', body: {} }),
  exportPreset: () =>
    request('customizer/export'),
  importPreset: (values) =>
    request('customizer/import', { method: 'POST', body: { values } }),
};

export const brand = {
  get: () =>
    request('brand'),
  save: (values) =>
    request('brand', { method: 'PUT', body: values }),
};

export const fonts = {
  list: () =>
    request('fonts'),
  save: (fontList) =>
    request('fonts', { method: 'PUT', body: { fonts: fontList } }),
};

export const components = {
  list: () =>
    request('components'),
  read: (file) =>
    request('components', { params: { file } }),
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
  search: (period = '30days') =>
    request('dashboard/search', { params: { period } }),
  cohorts: (period = '30days') =>
    request('dashboard/cohorts', { params: { period } }),
  funnels: (period = '30days') =>
    request('dashboard/funnels', { params: { period } }),
  geo: (period = '30days') =>
    request('dashboard/geo', { params: { period } }),
  geoStatus: () =>
    request('dashboard/geo/status'),
  geoUpload: (file) => {
    const formData = new FormData();
    formData.append('mmdb', file);
    formData.append('csrf_token', csrfToken);
    return request('dashboard/geo/upload', { method: 'POST', body: formData });
  },
  geoDelete: () =>
    request('dashboard/geo/delete', { method: 'DELETE' }),
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

// Releases
export const releases = {
  list: () => request('releases'),
  get: (id) => request('releases', { params: { id } }),
  create: (data) => request('releases', { method: 'POST', body: data }),
  update: (id, data) => request('releases', { method: 'PUT', params: { id }, body: data }),
  delete: (id) => request('releases', { method: 'DELETE', params: { id } }),
  publish: (id) => request('releases/publish', { method: 'POST', body: { id } }),
  rollback: (id) => request('releases/rollback', { method: 'POST', body: { id } }),
  addChange: (data) => request('releases/changes', { method: 'POST', body: data }),
  removeChange: (id) => request('releases/changes', { method: 'DELETE', params: { id } }),
};

// Workflows
export const workflows = {
  list: () => request('workflows'),
  get: (id) => request('workflows', { params: { id } }),
  create: (data) => request('workflows', { method: 'POST', body: data }),
  update: (id, data) => request('workflows', { method: 'PUT', params: { id }, body: data }),
  delete: (id) => request('workflows', { method: 'DELETE', params: { id } }),
  transition: (itemId, toStage, note = '') =>
    request('workflows/transition', { method: 'POST', body: { item_id: itemId, to_stage: toStage, note } }),
  bulkTransition: (itemIds, toStage, note = '') =>
    request('workflows/bulk-transition', { method: 'POST', body: { item_ids: itemIds, to_stage: toStage, note } }),
  history: (itemId) =>
    request('workflows/history', { params: { item_id: itemId } }),
  forCollection: (collectionId) =>
    request('workflows/for-collection', { params: { collection_id: collectionId } }),
};

// Setup Wizard
export const setup = {
  packs: () => request('setup/packs'),
  apply: (data) => request('setup/apply', { method: 'POST', body: data }),
  checklist: () => request('setup/checklist'),
  dismissChecklist: () => request('setup/checklist/dismiss', { method: 'POST', body: {} }),
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

// Backups
export const backup = {
  list: () => request('backup/list'),
  create: () => request('backup/create', { method: 'POST' }),
  download: (filename) => {
    const url = new URL(apiBase, window.location.origin);
    url.searchParams.set('action', 'backup/download');
    url.searchParams.set('filename', filename);
    window.open(url.toString(), '_blank');
  },
  delete: (filename) => request('backup/delete', { method: 'DELETE', params: { filename } }),
  restore: (file) => {
    const formData = new FormData();
    formData.append('backup', file);
    formData.append('csrf_token', csrfToken);
    return request('backup/restore', { method: 'POST', body: formData });
  },
  getSettings: () => request('backup/settings'),
  updateSettings: (data) => request('backup/settings', { method: 'PUT', body: data }),
};

// Comments & Collaboration
export const comments = {
  list: (params = {}) =>
    request('comments', { params }),
  create: (data) =>
    request('comments', { method: 'POST', body: data }),
  update: (id, data) =>
    request('comments', { method: 'PUT', params: { id }, body: data }),
  delete: (id) =>
    request('comments', { method: 'DELETE', params: { id } }),
  count: (params = {}) =>
    request('comments/count', { params }),
  activity: (limit = 50) =>
    request('comments/activity', { params: { limit } }),
};

// Review Tokens
export const reviewTokens = {
  list: () =>
    request('review-tokens'),
  create: (data) =>
    request('review-tokens', { method: 'POST', body: data }),
  delete: (id) =>
    request('review-tokens', { method: 'DELETE', params: { id } }),
  toggle: (id) =>
    request('review-tokens', { method: 'PUT', params: { id }, body: {} }),
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
