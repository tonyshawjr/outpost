<script>
  import CanvasFrame from './CanvasFrame.svelte';
  import { Monitor, Tablet, Smartphone, Columns3 } from 'lucide-svelte';

  let { editor, oncontext } = $props();

  let mode = $state('desktop');

  const SINGLE_WIDTH = { desktop: null, tablet: 820, mobile: 390 };
  const ALL = [
    { key: 'desktop', label: 'Desktop', w: 1366 },
    { key: 'tablet', label: 'Tablet', w: 820 },
    { key: 'mobile', label: 'Mobile', w: 390 },
  ];
  const GAP = 40;
  const ROW_H = 860;

  const DEVICES = [
    { key: 'desktop', label: 'Desktop', icon: Monitor },
    { key: 'tablet', label: 'Tablet', icon: Tablet },
    { key: 'mobile', label: 'Mobile', icon: Smartphone },
    { key: 'all', label: 'All screens', icon: Columns3 },
  ];

  let allWrapW = $state(0);
  let totalW = ALL.reduce((s, d) => s + d.w, 0) + (ALL.length - 1) * GAP;
  let zoom = $derived.by(() => {
    const avail = allWrapW - 48;
    return avail > 0 ? Math.min(1, avail / totalW) : 0.5;
  });

  function focusDevice(key) {
    mode = key;
  }
</script>

<div class="canvas">
  <div class="device-bar" role="group" aria-label="Canvas device width">
    {#each DEVICES as d (d.key)}
      <button class="dev" class:on={mode === d.key} onclick={() => (mode = d.key)} title={d.label} aria-label={d.label} aria-pressed={mode === d.key}>
        <d.icon size={16} aria-hidden="true" />
      </button>
    {/each}
    {#if mode !== 'all'}
      <span class="dev-w">{mode === 'desktop' ? 'Fluid' : `${SINGLE_WIDTH[mode]}px`}</span>
    {/if}
  </div>

  {#if mode === 'all'}
    <div class="all-scroll" bind:clientWidth={allWrapW}>
      <div class="all-sizer" style:width={`${totalW * zoom}px`} style:height={`${ROW_H * zoom}px`}>
        <div class="all-row" style:transform={`scale(${zoom})`} style:width={`${totalW}px`} style:height={`${ROW_H}px`}>
          {#each ALL as d (d.key)}
            <div class="device" style:width={`${d.w}px`}>
              <button class="device-label" onclick={() => focusDevice(d.key)} title={`Open ${d.label}`}>{d.label} · {d.w}px</button>
              <div class="device-frame">
                <CanvasFrame {editor} />
              </div>
            </div>
          {/each}
        </div>
      </div>
    </div>
  {:else}
    <div class="stage" class:fluid={mode === 'desktop'}>
      <div class="single" style:width={SINGLE_WIDTH[mode] ? `${SINGLE_WIDTH[mode]}px` : '100%'}>
        <CanvasFrame {editor} {oncontext} />
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
    border-radius: 7px;
    background: transparent;
    color: var(--sec);
    cursor: pointer;
  }
  .dev:hover { background: var(--hover); color: var(--text); }
  .dev.on { background: var(--purple-bg, var(--hover)); color: var(--purple-soft, var(--purple)); }
  .dev:focus-visible { outline: 2px solid var(--purple); outline-offset: 1px; }
  .dev-w {
    margin-left: 6px;
    font-size: 11px;
    color: var(--dim);
    font-variant-numeric: tabular-nums;
  }

  .stage {
    flex: 1;
    min-height: 0;
    display: flex;
    justify-content: center;
    padding: 24px;
    overflow: auto;
  }
  .stage.fluid { padding: 24px; }

  .single {
    flex-shrink: 0;
    max-width: 100%;
    min-width: 0;
    height: 100%;
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    background: #ffffff;
    box-shadow: var(--shadow-md);
    overflow: hidden;
  }
  .stage.fluid .single { flex: 1; }

  .all-scroll {
    flex: 1;
    min-height: 0;
    overflow: auto;
    padding: 24px;
  }
  .all-sizer { margin: 0 auto; }
  .all-row {
    display: flex;
    gap: 40px;
    transform-origin: top left;
  }
  .device {
    flex-shrink: 0;
    display: flex;
    flex-direction: column;
  }
  .device-label {
    align-self: flex-start;
    margin-bottom: 10px;
    padding: 4px 10px;
    border: none;
    border-radius: 6px;
    background: var(--raised);
    color: var(--sec);
    font-size: 15px;
    font-weight: 600;
    cursor: pointer;
  }
  .device-label:hover { color: var(--text); }
  .device-frame {
    flex: 1;
    min-height: 0;
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    background: #ffffff;
    box-shadow: var(--shadow-md);
    overflow: hidden;
  }
</style>
