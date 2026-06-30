<script>
  import { mount, unmount } from 'svelte';
  import CanvasContent from './CanvasContent.svelte';

  let { editor, oncontext } = $props();
  let iframeEl = $state(null);
  let styleEl = $state(null);

  const BASE_CSS = `
    html, body { margin: 0; padding: 0; }
    body { background: #ffffff; color: #111; }
    .oc-canvas { min-height: 100vh; }
    img { max-width: 100%; }
    [data-node-id] { cursor: pointer; }
    [data-node-id][data-selected] { outline: 2px solid #7C3AED; outline-offset: 1px; }
    [data-component-ref] { outline: 1px dashed #A78BFA; outline-offset: 1px; }
    [data-component-ref][data-selected] { outline: 2px solid #7C3AED; }
  `;

  $effect(() => {
    const iframe = iframeEl;
    if (!iframe) return;
    const doc = iframe.contentDocument;
    if (!doc) return;

    doc.open();
    doc.write('<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head><body><div class="oc-canvas"></div></body></html>');
    doc.close();

    const baseStyle = doc.createElement('style');
    baseStyle.textContent = BASE_CSS;
    doc.head.appendChild(baseStyle);

    const dynStyle = doc.createElement('style');
    doc.head.appendChild(dynStyle);
    styleEl = dynStyle;

    const surface = doc.querySelector('.oc-canvas');
    const app = mount(CanvasContent, { target: surface, props: { editor } });

    const onClick = (e) => {
      const t = e.target.closest('[data-node-id]');
      editor.select(t ? t.getAttribute('data-node-id') : null);
    };
    const onCtx = (e) => {
      const t = e.target.closest('[data-node-id]');
      if (!t) return;
      e.preventDefault();
      const id = t.getAttribute('data-node-id');
      editor.select(id);
      const rect = iframe.getBoundingClientRect();
      oncontext?.(id, rect.left + e.clientX, rect.top + e.clientY);
    };
    doc.addEventListener('click', onClick);
    doc.addEventListener('contextmenu', onCtx);

    return () => {
      try { unmount(app); } catch { void 0; }
      doc.removeEventListener('click', onClick);
      doc.removeEventListener('contextmenu', onCtx);
      styleEl = null;
    };
  });

  $effect(() => {
    const css = editor.allStyleCss;
    if (styleEl) styleEl.textContent = css;
  });
</script>

<div class="canvas">
  <div class="frame">
    <iframe bind:this={iframeEl} title="Page canvas"></iframe>
  </div>
</div>

<style>
  .canvas {
    flex: 1;
    min-width: 0;
    display: flex;
    padding: 24px;
    background: var(--bg);
  }

  .frame {
    flex: 1;
    min-width: 0;
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    background: #ffffff;
    box-shadow: var(--shadow-md);
    overflow: hidden;
  }

  iframe {
    width: 100%;
    height: 100%;
    border: 0;
    display: block;
  }
</style>
