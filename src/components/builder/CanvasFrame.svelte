<script>
  import { mount, unmount } from 'svelte';
  import CanvasContent from './CanvasContent.svelte';

  let { editor, oncontext, fitHeight = false, viewportHeight = 0, onwheel, preview = false, oncommand, pickMode = false, onpick } = $props();
  let iframeEl = $state(null);
  let styleEl = $state(null);
  const livePick = { mode: false, fn: null };
  let repositionBar = null;
  $effect(() => { livePick.mode = pickMode; livePick.fn = onpick; });
  $effect(() => {
    const body = iframeEl?.contentDocument?.body;
    if (body) body.style.cursor = pickMode ? 'crosshair' : '';
  });

  const BASE_CSS = `
    html, body { margin: 0; padding: 0; overscroll-behavior: none; }
    html { scrollbar-width: none; -ms-overflow-style: none; }
    html::-webkit-scrollbar, body::-webkit-scrollbar { width: 0; height: 0; display: none; }
    body { background: #ffffff; color: #111; }
    .oc-canvas { min-height: 100vh; }
    img { max-width: 100%; }
    [data-node-id] { scroll-margin: 96px 0; }
    .oc-embed { display: block; max-width: 100%; }
    .oc-embed iframe, .oc-embed img { display: block; width: 100%; height: auto; border: 0; }
    .oc-embed-empty { display: block; padding: 24px; text-align: center; color: #888; border: 1px dashed #ccc; }
  `;

  const CHROME_CSS = `
    [data-node-id] { cursor: pointer; }
    .oc-embed iframe { pointer-events: none; }
    [data-field] { outline: 1px dashed rgba(124,58,237,0.45); outline-offset: 1px; }
    [data-node-id][data-selected] { outline: 2px solid #7C3AED; outline-offset: 1px; }
    [data-component-ref] { outline: 1px dashed #A78BFA; outline-offset: 1px; }
    [data-component-ref][data-selected] { outline: 2px solid #7C3AED; }
    [data-loop] { position: relative; outline: 1px dashed rgba(16,185,129,0.55); outline-offset: 3px; min-height: 44px; }
    [data-loop][data-selected] { outline: 2px solid #7C3AED; }
    [data-empty] { min-height: 46px; }
    [data-empty]::before { content: "Type / to add"; display: flex; align-items: center; justify-content: center; min-height: 46px; color: #b7b2c6; font: 500 12px/1 system-ui, sans-serif; pointer-events: none; }
    .oc-loop-badge { position: absolute; top: 0; left: 0; transform: translateY(-100%); font: 700 11px/1.5 system-ui, sans-serif; background: #10b981; color: #04140d; padding: 2px 8px; border-radius: 6px 6px 0 0; white-space: nowrap; pointer-events: none; }
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

    const isPreview = preview;
    const baseStyle = doc.createElement('style');
    baseStyle.textContent = withViewportHeight(BASE_CSS + (isPreview ? '' : CHROME_CSS), fitHeight ? viewportHeight : 0);
    doc.head.appendChild(baseStyle);

    const dynStyle = doc.createElement('style');
    doc.head.appendChild(dynStyle);
    styleEl = dynStyle;

    const surface = doc.querySelector('.oc-canvas');
    const app = mount(CanvasContent, { target: surface, props: { editor, preview: isPreview } });

    const onClick = (e) => {
      if (isPreview) return;
      const t = e.target.closest('[data-node-id]');
      const id = t ? t.getAttribute('data-node-id') : null;
      if (livePick.mode) { if (id && livePick.fn) livePick.fn(id); return; }
      editor.select(id);
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
    const onKeyDown = (e) => {
      if (e.key === '/' && !isPreview && oncommand) { e.preventDefault(); oncommand(); }
    };
    const raf = (doc.defaultView || window).requestAnimationFrame;
    const onPreview = (e) => {
      const id = e.detail?.id;
      const mot = editor.tree?.nodes?.[id]?.props?.motion;
      if (!mot || !mot.trigger) return;
      const el = doc.querySelector(`[data-node-id="${id}"]`);
      if (!el) return;
      const eff = mot.effect || 'fade';
      const dist = mot.distance || 24, dur = mot.duration || 600, delay = mot.delay || 0;
      const from = eff === 'slide-up' ? `translateY(${dist}px)` : eff === 'slide-down' ? `translateY(-${dist}px)`
        : eff === 'slide-left' ? `translateX(${dist}px)` : eff === 'slide-right' ? `translateX(-${dist}px)`
        : eff === 'scale' ? 'scale(0.92)' : 'none';
      el.style.transition = 'none';
      el.style.opacity = '0';
      el.style.transform = from;
      el.getBoundingClientRect();
      raf(() => raf(() => {
        el.style.transition = `opacity ${dur}ms ease ${delay}ms, transform ${dur}ms cubic-bezier(0.16,1,0.3,1) ${delay}ms`;
        el.style.opacity = '1';
        el.style.transform = 'none';
        setTimeout(() => { el.style.transition = ''; el.style.opacity = ''; el.style.transform = ''; }, dur + delay + 120);
      }));
    };
    doc.addEventListener('click', onClick);
    if (oncontext) doc.addEventListener('contextmenu', onCtx);
    if (oncommand) doc.addEventListener('keydown', onKeyDown);
    if (!isPreview) window.addEventListener('outpost:motion-preview', onPreview);

    const onWheelFwd = (e) => {
      e.preventDefault();
      const rect = iframe.getBoundingClientRect();
      const factor = iframe.clientWidth ? rect.width / iframe.clientWidth : 1;
      onwheel({
        deltaX: e.deltaX,
        deltaY: e.deltaY,
        ctrl: e.ctrlKey || e.metaKey,
        x: rect.left + e.clientX * factor,
        y: rect.top + e.clientY * factor,
      });
    };
    if (onwheel) doc.addEventListener('wheel', onWheelFwd, { passive: false });

    let bar = null;
    if (!fitHeight && !isPreview) {
      bar = doc.createElement('div');
      bar.style.cssText = 'position:fixed;z-index:2147483000;display:none;gap:2px;padding:3px;background:#7C3AED;border-radius:8px;box-shadow:0 4px 14px rgba(0,0,0,0.25);';
      bar.addEventListener('mousedown', (ev) => ev.stopPropagation());
      const mkBtn = (label, title, fn) => {
        const b = doc.createElement('button');
        b.type = 'button';
        b.textContent = label;
        b.title = title;
        b.setAttribute('aria-label', title);
        b.style.cssText = 'width:26px;height:24px;border:0;border-radius:6px;background:transparent;color:#fff;font:600 13px/1 system-ui,sans-serif;cursor:pointer;';
        b.addEventListener('mouseenter', () => { b.style.background = 'rgba(255,255,255,0.22)'; });
        b.addEventListener('mouseleave', () => { b.style.background = 'transparent'; });
        b.addEventListener('click', (ev) => { ev.stopPropagation(); fn(); });
        return b;
      };
      const parentBtn = mkBtn('↑', 'Select parent', () => {
        const id = editor.selectedId;
        if (!id) return;
        const nodes = editor.tree.nodes;
        for (const nid in nodes) { if (nodes[nid].children && nodes[nid].children.includes(id)) { editor.select(nid); return; } }
      });
      const dupBtn = mkBtn('⧉', 'Duplicate', () => { if (editor.selectedId) editor.duplicate(editor.selectedId); });
      const delBtn = mkBtn('✕', 'Delete', () => { const id = editor.selectedId; if (id && id !== editor.tree.root) editor.remove(id); });
      bar.appendChild(parentBtn);
      bar.appendChild(dupBtn);
      bar.appendChild(delBtn);
      doc.body.appendChild(bar);

      const reposition = () => {
        const id = editor.selectedId;
        const el = (id && !livePick.mode) ? doc.querySelector(`[data-node-id="${id}"]`) : null;
        if (!el) { bar.style.display = 'none'; return; }
        const r = el.getBoundingClientRect();
        const isRoot = id === editor.tree.root;
        delBtn.disabled = isRoot;
        delBtn.style.opacity = isRoot ? '0.4' : '1';
        bar.style.display = 'flex';
        bar.style.top = (r.top - 30 < 4 ? r.top + 4 : r.top - 30) + 'px';
        bar.style.left = Math.max(4, r.left) + 'px';
      };
      doc.addEventListener('scroll', reposition, { passive: true, capture: true });
      repositionBar = reposition;
      reposition();
    }

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
      doc.removeEventListener('keydown', onKeyDown);
      doc.removeEventListener('wheel', onWheelFwd);
      window.removeEventListener('outpost:motion-preview', onPreview);
      repositionBar = null;
      styleEl = null;
    };
  });

  $effect(() => {
    const _ = editor.selectedId;
    void _;
    if (repositionBar) (requestAnimationFrame || window.requestAnimationFrame)(() => repositionBar && repositionBar());
  });

  $effect(() => {
    const css = editor.allStyleCss;
    if (styleEl) styleEl.textContent = withViewportHeight(css, fitHeight ? viewportHeight : 0);
  });

  $effect(() => {
    const id = editor.selectedId;
    const iframe = iframeEl;
    if (!id || !iframe || fitHeight) return;
    const doc = iframe.contentDocument;
    if (!doc) return;
    const raf = (doc.defaultView || window).requestAnimationFrame;
    raf(() => {
      const el = doc.querySelector(`[data-node-id="${id}"]`);
      if (el) el.scrollIntoView({ block: 'nearest', inline: 'nearest', behavior: 'smooth' });
    });
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
