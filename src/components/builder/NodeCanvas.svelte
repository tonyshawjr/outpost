<script>
  import CanvasFrame from './CanvasFrame.svelte';
  import { Monitor, Tablet, Smartphone, Columns3, Minus, Plus, Maximize } from 'lucide-svelte';

  let { editor, oncontext, oncommand, preview = false } = $props();

  let mode = $state('desktop');

  const SINGLE_WIDTH = { desktop: null, tablet: 820, mobile: 390 };
  const ALL = [
    { key: 'desktop', label: 'Desktop', w: 1366, h: 800 },
    { key: 'tablet', label: 'Tablet', w: 820, h: 1180 },
    { key: 'mobile', label: 'Mobile', w: 390, h: 844 },
  ];

  const DEVICES = [
    { key: 'desktop', label: 'Desktop', icon: Monitor },
    { key: 'tablet', label: 'Tablet', icon: Tablet },
    { key: 'mobile', label: 'Mobile', icon: Smartphone },
    { key: 'all', label: 'All screens', icon: Columns3 },
  ];

  let viewportEl = $state(null);
  let rowEl = $state(null);
  let zoom = $state(1);
  let panX = $state(0);
  let panY = $state(0);
  let dragging = $state(false);

  const clampZoom = (z) => Math.max(0.05, Math.min(2, z));

  $effect(() => {
    editor.setBreakpoint(mode);
  });

  function fit() {
    const vp = viewportEl, row = rowEl;
    if (!vp || !row) return;
    const w = row.scrollWidth, h = row.scrollHeight;
    if (!w || !h) return;
    const z = clampZoom(Math.min((vp.clientWidth - 64) / w, (vp.clientHeight - 64) / h));
    zoom = z;
    panX = (vp.clientWidth - w * z) / 2;
    panY = Math.max(24, (vp.clientHeight - h * z) / 2);
  }

  function zoomAt(factor, cx, cy) {
    const vp = viewportEl;
    if (!vp) return;
    const rect = vp.getBoundingClientRect();
    const mx = (cx ?? rect.left + rect.width / 2) - rect.left;
    const my = (cy ?? rect.top + rect.height / 2) - rect.top;
    const wx = (mx - panX) / zoom;
    const wy = (my - panY) / zoom;
    const nz = clampZoom(zoom * factor);
    panX = mx - wx * nz;
    panY = my - wy * nz;
    zoom = nz;
  }

  function applyWheel({ deltaX, deltaY, ctrl, x, y }) {
    if (ctrl) zoomAt(deltaY < 0 ? 1.1 : 0.9, x, y);
    else { panX -= deltaX; panY -= deltaY; }
  }
  function onWheel(e) {
    e.preventDefault();
    applyWheel({ deltaX: e.deltaX, deltaY: e.deltaY, ctrl: e.ctrlKey || e.metaKey, x: e.clientX, y: e.clientY });
  }

  function onPanStart(e) {
    if (e.button !== 0 && e.button !== 1) return;
    dragging = true;
    const sx = e.clientX, sy = e.clientY, px = panX, py = panY;
    const move = (ev) => { panX = px + (ev.clientX - sx); panY = py + (ev.clientY - sy); };
    const up = () => { dragging = false; window.removeEventListener('mousemove', move); window.removeEventListener('mouseup', up); };
    window.addEventListener('mousemove', move);
    window.addEventListener('mouseup', up);
  }

  $effect(() => {
    if (mode !== 'all') return;
    const row = rowEl;
    if (!row) return;
    const ro = new ResizeObserver(() => { if (zoom === 1 && panX === 0 && panY === 0) fit(); });
    ro.observe(row);
    const t = setTimeout(fit, 60);
    return () => { ro.disconnect(); clearTimeout(t); };
  });
</script>

<div class="canvas">
  <div class="device-bar" role="group" aria-label="Canvas device width">
    {#each DEVICES as d (d.key)}
      <button class="dev" class:on={mode === d.key} onclick={() => (mode = d.key)} title={d.label} aria-label={d.label} aria-pressed={mode === d.key}>
        <d.icon size={16} aria-hidden="true" />
      </button>
    {/each}
    {#if mode === 'all'}
      <div class="zoom" role="group" aria-label="Zoom">
        <button class="zbtn" onclick={() => zoomAt(0.9)} aria-label="Zoom out"><Minus size={14} aria-hidden="true" /></button>
        <button class="zlevel" onclick={fit} title="Fit to screen">{Math.round(zoom * 100)}%</button>
        <button class="zbtn" onclick={() => zoomAt(1.1)} aria-label="Zoom in"><Plus size={14} aria-hidden="true" /></button>
        <button class="zbtn" onclick={fit} aria-label="Fit to screen"><Maximize size={14} aria-hidden="true" /></button>
      </div>
    {:else}
      <span class="dev-w">{mode === 'desktop' ? 'Fluid' : `${SINGLE_WIDTH[mode]}px`}</span>
    {/if}
  </div>

  {#if mode === 'all'}
    <div
      class="fig-viewport"
      class:dragging
      bind:this={viewportEl}
      onwheel={onWheel}
      onmousedown={onPanStart}
      role="application"
      aria-label="Multi-screen canvas — scroll to pan, ctrl/cmd-scroll to zoom, drag to move"
    >
      <div class="fig-world" bind:this={rowEl} style:transform={`translate(${panX}px, ${panY}px) scale(${zoom})`}>
        {#each ALL as d (d.key)}
          <div class="device" style:width={`${d.w}px`}>
            <button class="device-label" onclick={() => (mode = d.key)} title={`Open ${d.label}`}>{d.label} · {d.w}px</button>
            <div class="device-frame">
              <CanvasFrame {editor} fitHeight viewportHeight={d.h} onwheel={applyWheel} {preview} {oncommand} />
            </div>
          </div>
        {/each}
      </div>
    </div>
  {:else}
    <div class="stage" class:fluid={mode === 'desktop'}>
      <div class="single" style:width={SINGLE_WIDTH[mode] ? `${SINGLE_WIDTH[mode]}px` : '100%'}>
        <CanvasFrame {editor} oncontext={preview ? undefined : oncontext} {preview} {oncommand} />
      </div>
    </div>
  {/if}
</div>

<style>
  .canvas {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    background: var(--bg);
  }

  .device-bar {
    display: flex;
    align-items: center;
    gap: 4px;
    padding: 8px 16px;
    border-bottom: 1px solid var(--border);
    flex-shrink: 0;
  }
  .dev {
    display: inline-flex;
    padding: 7px;
    border: none;
    background: transparent;
    color: var(--sec);
    cursor: pointer;
  }
  .dev:hover { background: var(--hover); color: var(--text); }
  .dev.on { background: var(--purple-bg, var(--hover)); color: var(--purple-soft, var(--purple)); }
  .dev:focus-visible { outline: 2px solid var(--purple); outline-offset: 1px; }
  .dev-w { margin-left: 6px; font-size: 11px; color: var(--dim); font-variant-numeric: tabular-nums; }

  .zoom { margin-left: 10px; display: inline-flex; align-items: center; gap: 2px; }
  .zbtn { display: inline-flex; padding: 6px; border: none; background: transparent; color: var(--sec); cursor: pointer; }
  .zbtn:hover { background: var(--hover); color: var(--text); }
  .zbtn:focus-visible { outline: 2px solid var(--purple); outline-offset: 1px; }
  .zlevel {
    min-width: 46px;
    padding: 5px 6px;
    border: none;
    background: transparent;
    color: var(--sec);
    font-size: 12px;
    font-variant-numeric: tabular-nums;
    cursor: pointer;
  }
  .zlevel:hover { color: var(--text); }

  .stage {
    flex: 1;
    min-height: 0;
    display: flex;
    justify-content: center;
    padding: 24px;
    overflow: auto;
  }
  .single {
    flex-shrink: 0;
    max-width: 100%;
    min-width: 0;
    height: 100%;
    border: 1px solid var(--border);
    background: #ffffff;
    overflow: hidden;
  }
  .stage.fluid .single { flex: 1; }

  .fig-viewport {
    flex: 1;
    min-height: 0;
    position: relative;
    overflow: hidden;
    overscroll-behavior: none;
    cursor: grab;
    background: var(--bg);
  }
  .fig-viewport.dragging { cursor: grabbing; }
  .fig-viewport.dragging :global(iframe) { pointer-events: none; }

  .fig-world {
    position: absolute;
    top: 0;
    left: 0;
    display: flex;
    align-items: flex-start;
    gap: 64px;
    transform-origin: 0 0;
    will-change: transform;
  }
  .device { flex-shrink: 0; display: flex; flex-direction: column; }
  .device-label {
    align-self: flex-start;
    margin-bottom: 12px;
    padding: 6px 12px;
    border: none;
    background: var(--raised);
    color: var(--sec);
    font-size: 16px;
    font-weight: 600;
    cursor: pointer;
  }
  .device-label:hover { color: var(--text); }
  .device-frame {
    border: 1px solid var(--border);
    background: #ffffff;
    box-shadow: var(--shadow-md);
    overflow: hidden;
  }
  .device-frame :global(iframe) { display: block; }
</style>
