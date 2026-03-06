/**
 * Outpost CMS — Svelte Stores
 */
import { writable, derived } from 'svelte/store';

// Auth
export const user = writable(null);
export const isAuthenticated = derived(user, ($user) => $user !== null);
export const appVersion = writable('');
export const updateAvailable = writable(false);
export const latestVersion = writable(null);

// Role-based derived stores
export const isSuperAdmin = derived(user, ($user) => $user?.role === 'super_admin');
export const isAdmin = derived(user, ($user) => ['super_admin', 'admin'].includes($user?.role));
export const isDeveloper = derived(user, ($user) => $user?.role === 'developer');
export const isEditor = derived(user, ($user) => $user?.role === 'editor');
export const canManageUsers = derived(user, ($user) => ['super_admin', 'admin'].includes($user?.role));
export const canManageSettings = derived(user, ($user) => ['super_admin', 'admin'].includes($user?.role));
export const canAccessCodeEditor = derived(user, ($user) => ['super_admin', 'admin', 'developer'].includes($user?.role));
export const canManageMembers = derived(user, ($user) => ['super_admin', 'admin'].includes($user?.role));
export const canManageChannels = derived(user, ($user) => ['super_admin', 'admin'].includes($user?.role));
export const canBuildForms = derived(user, ($user) => ['super_admin', 'admin'].includes($user?.role));
export const collectionGrants = writable(null); // null = all, array = restricted IDs

// Navigation — parse initial state from URL hash
function parseHash() {
  if (typeof window === 'undefined') return {};
  const hash = window.location.hash.replace(/^#\/?/, '');
  if (!hash) return {};
  const [route, ...rest] = hash.split('/');
  const params = {};
  for (const segment of rest) {
    const [key, value] = segment.split('=');
    if (key && value !== undefined) params[key] = value;
  }
  return { route, params };
}

const initial = parseHash();
export const currentRoute = writable(initial.route || 'dashboard');
export const currentPageId = writable(initial.params?.pageId ? Number(initial.params.pageId) : null);
export const currentCollectionSlug = writable(initial.params?.collection || null);
export const currentItemId = writable(initial.params?.itemId ? Number(initial.params.itemId) : null);
export const currentProfileUserId = writable(initial.params?.userId ? Number(initial.params.userId) : null);
export const currentStatusFilter = writable(initial.params?.statusFilter || 'all');
export const currentFolderCollectionId = writable(initial.params?.folderCollectionId ? Number(initial.params.folderCollectionId) : null);
export const currentFolderId = writable(initial.params?.folderId ? Number(initial.params.folderId) : null);
export const currentLabelId = writable(initial.params?.labelId ? Number(initial.params.labelId) : null);
export const currentSettingsSection = writable(initial.params?.section || 'general');
export const currentFormId = writable(initial.params?.formId ? Number(initial.params.formId) : null);
export const currentChannelId = writable(initial.params?.channelId ? Number(initial.params.channelId) : null);

// Data
export const pagesList = writable([]);
export const collectionsList = writable([]);
export const mediaList = writable([]);
export const statsData = writable(null);

// Editor state (shared between CollectionEditor and RightSidebar)
export const editorItem = writable(null);
export const editorCollection = writable(null);
export const editorReloadSignal = writable(0);
export const revisionReloadSignal = writable(0);

// UI
export const sidebarOpen = writable(true);
export const searchOpen = writable(false);
function getInitialDarkMode() {
  if (typeof window === 'undefined') return false;
  const saved = localStorage.getItem('outpost-dark-mode');
  if (saved !== null) return saved === 'true';
  return window.matchMedia('(prefers-color-scheme: dark)').matches;
}

function createDarkModeStore() {
  const store = writable(getInitialDarkMode());
  store.subscribe((v) => {
    if (typeof window !== 'undefined') {
      localStorage.setItem('outpost-dark-mode', String(v));
    }
  });
  return store;
}

export const darkMode = createDarkModeStore();
export const toasts = writable([]);
export const loading = writable(false);

// Toast helpers
let toastId = 0;
export function addToast(message, type = 'success', duration = 3000) {
  const id = ++toastId;
  toasts.update((t) => [...t, { id, message, type }]);
  if (duration > 0) {
    setTimeout(() => {
      toasts.update((t) => t.filter((toast) => toast.id !== id));
    }, duration);
  }
  return id;
}

export function removeToast(id) {
  toasts.update((t) => t.filter((toast) => toast.id !== id));
}

// Router
function buildHash(route, params = {}) {
  let hash = '#/' + route;
  const parts = [];
  if (params.pageId !== undefined) parts.push('pageId=' + params.pageId);
  if (params.collectionSlug !== undefined) parts.push('collection=' + params.collectionSlug);
  if (params.itemId !== undefined) parts.push('itemId=' + params.itemId);
  if (params.userId !== undefined) parts.push('userId=' + params.userId);
  if (params.statusFilter !== undefined && params.statusFilter !== 'all') parts.push('statusFilter=' + params.statusFilter);
  if (params.folderCollectionId !== undefined) parts.push('folderCollectionId=' + params.folderCollectionId);
  if (params.folderId !== undefined) parts.push('folderId=' + params.folderId);
  if (params.labelId !== undefined) parts.push('labelId=' + params.labelId);
  if (params.section !== undefined) parts.push('section=' + params.section);
  if (params.formId !== undefined) parts.push('formId=' + params.formId);
  if (params.channelId !== undefined) parts.push('channelId=' + params.channelId);
  if (parts.length) hash += '/' + parts.join('/');
  return hash;
}

let navigating = false;

export function navigate(route, params = {}) {
  navigating = true;
  currentRoute.set(route);
  currentPageId.set(params.pageId ?? null);
  currentCollectionSlug.set(params.collectionSlug ?? null);
  currentItemId.set(params.itemId ?? null);
  currentProfileUserId.set(params.userId ?? null);
  currentStatusFilter.set(params.statusFilter ?? 'all');
  currentFolderCollectionId.set(params.folderCollectionId ?? null);
  currentFolderId.set(params.folderId ?? null);
  currentLabelId.set(params.labelId ?? null);
  currentSettingsSection.set(params.section ?? 'general');
  currentFormId.set(params.formId ?? null);
  currentChannelId.set(params.channelId ?? null);
  if (typeof window !== 'undefined') {
    window.location.hash = buildHash(route, params);
  }
  navigating = false;
}

// Listen for browser back/forward
if (typeof window !== 'undefined') {
  window.addEventListener('hashchange', () => {
    if (navigating) return;
    const { route, params } = parseHash();
    if (route) {
      currentRoute.set(route);
      currentPageId.set(params?.pageId ? Number(params.pageId) : null);
      currentCollectionSlug.set(params?.collection || null);
      currentItemId.set(params?.itemId ? Number(params.itemId) : null);
      currentProfileUserId.set(params?.userId ? Number(params.userId) : null);
      currentStatusFilter.set(params?.statusFilter || 'all');
      currentFolderCollectionId.set(params?.folderCollectionId ? Number(params.folderCollectionId) : null);
      currentFolderId.set(params?.folderId ? Number(params.folderId) : null);
      currentLabelId.set(params?.labelId ? Number(params.labelId) : null);
      currentSettingsSection.set(params?.section || 'general');
      currentFormId.set(params?.formId ? Number(params.formId) : null);
      currentChannelId.set(params?.channelId ? Number(params.channelId) : null);
    }
  });
}
