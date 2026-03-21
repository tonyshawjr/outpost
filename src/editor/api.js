// Outpost Editor — Lightweight API helper
// Standalone fetch utilities for the frontend editor overlay.
// Cannot import from src/lib/api.js (admin SPA build).

const getConfig = () => window.__OUTPOST_EDITOR__ || window.__OPE || {};

export function getApiUrl() {
  return getConfig().apiUrl || '/outpost/api.php';
}

export function getCsrfToken() {
  return getConfig().csrfToken || getConfig().csrf || '';
}

export function getPageId() {
  return getConfig().pageId || null;
}

export function getPagePath() {
  return getConfig().pagePath || window.location.pathname;
}

/**
 * Generic API request — returns parsed JSON.
 */
export async function request(action, options = {}) {
  const url = new URL(getApiUrl(), window.location.origin);
  url.searchParams.set('action', action);
  if (options.params) {
    for (const [k, v] of Object.entries(options.params)) {
      url.searchParams.set(k, v);
    }
  }

  const headers = { 'X-CSRF-Token': getCsrfToken() };
  const method = options.method || 'GET';
  let body = undefined;

  if (method !== 'GET' && options.body !== undefined) {
    if (options.body instanceof FormData) {
      body = options.body;
    } else {
      headers['Content-Type'] = 'application/json';
      body = JSON.stringify(options.body);
    }
  }

  const res = await fetch(url.toString(), {
    method,
    headers,
    credentials: 'include',
    body,
  });

  if (!res.ok) {
    const data = await res.json().catch(() => ({}));
    throw new Error(data.error || `Request failed: ${res.status}`);
  }
  return res.json();
}

/**
 * Stream a Ranger chat — returns the raw Response for SSE parsing.
 */
export async function streamChat(payload, signal) {
  const url = new URL(getApiUrl(), window.location.origin);
  url.searchParams.set('action', 'ranger/chat');

  const res = await fetch(url.toString(), {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': getCsrfToken(),
    },
    credentials: 'include',
    signal,
    body: JSON.stringify(payload),
  });

  if (!res.ok) {
    const errText = await res.text();
    let msg;
    try {
      msg = JSON.parse(errText).error;
    } catch {
      msg = errText || res.statusText;
    }
    throw new Error(msg || `Request failed: ${res.status}`);
  }

  return res;
}
