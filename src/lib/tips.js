/**
 * Contextual tips — shown once per admin section, dismissed per-browser.
 */
export const tips = {
  pages: 'Pages are auto-discovered from your theme templates. Visit a page on your site to register it here.',
  collections: 'Collections are reusable content types like blog posts, projects, or team members.',
  media: 'Drag and drop files anywhere on this page to upload. Images are automatically optimized.',
  globals: 'Globals are site-wide values like your site name, logo, and social links — available in every template.',
  navigation: 'Create menus and add links to pages, collections, or custom URLs. Use {% for item in menu.slug %} in templates.',
  forms: 'Build forms visually and embed them with {% form "slug" %} in any template.',
  themes: 'Themes control your site\'s look and feel. Duplicate a theme to customize it without affecting the original.',
  codeEditor: 'Edit theme templates, CSS, and partials directly. Changes are saved to your active theme.',
};

const STORAGE_KEY = 'outpost-dismissed-tips';

export function isDismissed(key) {
  try {
    const data = JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}');
    return !!data[key];
  } catch {
    return false;
  }
}

export function dismissTip(key) {
  try {
    const data = JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}');
    data[key] = true;
    localStorage.setItem(STORAGE_KEY, JSON.stringify(data));
  } catch {
    // Ignore storage errors
  }
}
