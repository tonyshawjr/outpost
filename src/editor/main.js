// Outpost CMS — Frontend Editor Overlay (v4.0)
// The drawer + bridge system replaces the old inline editing entirely.

import './styles.css';
import { mount } from 'svelte';
import Editor from './Editor.svelte';

function boot() {
  try {
    console.log('[OPE] Booting frontend editor overlay...');

    // Mount the Svelte overlay (drawer-based editing only — old inline editing disabled)
    const container = document.createElement('div');
    container.id = 'outpost-editor-root';
    document.body.appendChild(container);

    mount(Editor, { target: container });

    console.log('[OPE] Frontend editor overlay mounted');
  } catch (err) {
    console.error('[OPE] Boot error:', err);
  }
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', boot);
} else {
  boot();
}
