<script>
  import { mount, unmount } from 'svelte';
  import CanvasContent from './CanvasContent.svelte';

  let { editor, oncontext, fitHeight = false, viewportHeight = 0 } = $props();
  let iframeEl = $state(null);
  let styleEl = $state(null);

  const BASE_CSS = `
    html, body { margin: 0; padding: 0; }
    html { scrollbar-width: none; -ms-overflow-style: none; }
    html::-webkit-scrollbar, body::-webkit-scrollbar { width: 0; height: 0; display: none; }
    body { background: #ffffff; color: #111; }
    .oc-canvas { min-height: 100vh; }
    img { max-width: 100%; }
    [data-node-id] { cursor: pointer; }
    [data-field] { outline: 1px dashed rgba(124,58,237,0.45); outline-offset: 1px; }
    [data-node-id][data-selected] { outline: 2px solid #7C3AED; outline-offset: 1px; }
    [data-component-ref] { outline: 1px dashed #A78BFA; outline-offset: 1px; }
    [data-component-ref][data-selected] { outline: 2px solid #7C3AED; }
  `;

  function withViewportHeight(css, vh) {
    if (!vh) return css;
    return css.replace(/(-?[\d.]+)(dvh|svh|lvh|vh)\b/gi, (_, n) => `${(parseFloat(n) / 100) * vh}px`);
  }

  $effect(() => {
    const iframe = iframeEl;
    if (!iframe) return;
    const doc = iframe.contentDocument;
    if (!doc) return;

    doc.open();
    doc.write('<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head><body><div class="oc-canvas"></div></body></html>');
    doc.close();

    const baseStyle = doc.createElement('style');
    baseStyle.textContent = withViewportHeight(BASE_CSS, fitHeight ? viewportHeight : 0);
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
    if (oncontext) doc.addEventListener('contextmenu', onCtx);

    let ro = null;
    if (fitHeight) {
      const fit = () => {
        const h = Math.max(doc.documentElement.scrollHeight, doc.body.scrollHeight);
        if (h) iframe.style.height = h + 'px';
      };
      ro = new ResizeObserver(fit);
      ro.observe(doc.documentElement);
      ro.observe(doc.body);
      fit();
    }

    return () => {
      try { unmount(app); } catch { void 0; }
      if (ro) ro.disconnect();
      doc.removeEventListener('click', onClick);
      doc.removeEventListener('contextmenu', onCtx);
      styleEl = null;
    };
  });

  $effect(() => {
    const css = editor.allStyleCss;
    if (styleEl) styleEl.textContent = withViewportHeight(css, fitHeight ? viewportHeight : 0);
  });
</script>

<iframe bind:this={iframeEl} title="Page canvas"></iframe>

<style>
  iframe {
    width: 100%;
    height: 100%;
    border: 0;
    display: block;
    background: #ffffff;
  }
</style>
