<script>
  import { onMount } from 'svelte';
  import { analytics as analyticsApi } from '$lib/api.js';
  import { addToast } from '$lib/stores.js';

  // ── State ──────────────────────────────────────────────────
  let period = $state('30days');
  let events = $state(null);
  let loading = $state(true);
  let chartCanvas = $state(null);
  let chartInstance = null;
  let expandedEvent = $state(null);
  let detailData = $state(null);
  let detailLoading = $state(false);
  let detailChartCanvas = $state(null);
  let detailChartInstance = null;

  const PERIODS = [
    { val: '7days',    label: 'Last 7 days' },
    { val: '30days',   label: 'Last 30 days' },
    { val: '90days',   label: 'Last 90 days' },
    { val: '12months', label: 'Last 12 months' },
  ];

  // ── Helpers ────────────────────────────────────────────────
  function fmtNum(n) {
    if (n == null) return '\u2014';
    if (n >= 1000000) return (n / 1000000).toFixed(1) + 'M';
    if (n >= 1000) return (n / 1000).toFixed(1) + 'K';
    return String(n);
  }

  function trendBadge(change) {
    if (change == null) return '';
    if (change > 0) return `\u2191 +${change}%`;
    if (change < 0) return `\u2193 ${change}%`;
    return '\u2192 same';
  }

  function trendClass(change) {
    if (change == null) return '';
    if (change > 0) return 'trend-up';
    if (change < 0) return 'trend-down';
    return 'trend-flat';
  }

  function timeAgo(dateStr) {
    if (!dateStr) return '\u2014';
    const now = Date.now();
    const then = new Date(dateStr).getTime();
    const diffSec = Math.floor((now - then) / 1000);
    if (diffSec < 60) return 'just now';
    if (diffSec < 3600) return `${Math.floor(diffSec / 60)}m ago`;
    if (diffSec < 86400) return `${Math.floor(diffSec / 3600)}h ago`;
    if (diffSec < 604800) return `${Math.floor(diffSec / 86400)}d ago`;
    return new Date(dateStr).toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
  }

  function formatProps(props) {
    if (!props) return [];
    let obj = props;
    if (typeof props === 'string') {
      try { obj = JSON.parse(props); } catch { return []; }
    }
    return Object.entries(obj).filter(([, v]) => v != null && v !== '');
  }

  // ── Chart.js ───────────────────────────────────────────────
  async function loadChartJs() {
    if (window.Chart) return window.Chart;
    return new Promise((resolve, reject) => {
      const s = document.createElement('script');
      s.src = 'https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js';
      s.onload  = () => resolve(window.Chart);
      s.onerror = reject;
      document.head.appendChild(s);
    });
  }

  async function buildChart(canvas, data, instance) {
    if (!canvas || !data?.length) return null;

    try {
      const ChartJs = await loadChartJs();
      if (instance) { instance.destroy(); }

      const style    = getComputedStyle(document.documentElement);
      const textMuted = style.getPropertyValue('--text-muted').trim() || '#8A857D';
      const forest   = '#2D5A47';
      const isDark   = document.documentElement.classList.contains('dark');
      const ctx      = canvas.getContext('2d');

      const labels = data.map(d => {
        const dt = new Date(d.date + 'T00:00:00');
        return dt.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
      });

      const gradient = ctx.createLinearGradient(0, 0, 0, canvas.height || 240);
      gradient.addColorStop(0, 'rgba(45, 90, 71, 0.10)');
      gradient.addColorStop(1, 'rgba(45, 90, 71, 0.00)');

      return new ChartJs(ctx, {
        type: 'line',
        data: {
          labels,
          datasets: [{
            label: 'Events',
            data: data.map(d => d.count),
            borderColor: forest,
            backgroundColor: gradient,
            fill: true,
            tension: 0.4,
            pointRadius: 0,
            pointHoverRadius: 4,
            pointHoverBackgroundColor: forest,
            pointHoverBorderColor: '#fff',
            pointHoverBorderWidth: 2,
            borderWidth: 2,
          }],
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: { display: false },
            tooltip: {
              backgroundColor: isDark ? '#F5F3EF' : '#2D2A26',
              titleColor:       isDark ? '#2D2A26' : '#FFF',
              bodyColor:        isDark ? '#2D2A26' : '#FFF',
              borderWidth: 0, cornerRadius: 8, padding: 10,
              mode: 'index', intersect: false,
            },
          },
          scales: {
            x: {
              grid: { display: false },
              ticks: { color: textMuted, font: { family: 'Inter', size: 12 }, maxTicksLimit: 8 },
              border: { display: false },
            },
            y: {
              grid: { color: isDark ? 'rgba(255,255,255,0.06)' : 'rgba(229,225,218,0.6)', drawTicks: false },
              ticks: { display: false },
              border: { display: false },
              beginAtZero: true,
            },
          },
          interaction: { mode: 'index', intersect: false },
        },
      });
    } catch (err) {
      console.error('Chart.js failed:', err);
      return null;
    }
  }

  // Build main chart when data or canvas change
  $effect(() => {
    const canvas = chartCanvas;
    const chartData = events?.chart;
    if (!canvas || !chartData) return;
    buildChart(canvas, chartData, chartInstance).then(inst => {
      if (inst) chartInstance = inst;
    });
    return () => {
      if (chartInstance) { chartInstance.destroy(); chartInstance = null; }
    };
  });

  // Build detail chart when detail data or canvas change
  $effect(() => {
    const canvas = detailChartCanvas;
    const chartData = detailData?.chart;
    if (!canvas || !chartData) return;
    buildChart(canvas, chartData, detailChartInstance).then(inst => {
      if (inst) detailChartInstance = inst;
    });
    return () => {
      if (detailChartInstance) { detailChartInstance.destroy(); detailChartInstance = null; }
    };
  });

  // ── Data loading ───────────────────────────────────────────
  async function loadEvents(p = '30days') {
    loading = true;
    try {
      const res = await analyticsApi.events(p);
      events = res.data;
    } catch (err) {
      addToast('Failed to load events data', 'error');
    } finally {
      loading = false;
    }
  }

  async function changePeriod(p) {
    period = p;
    expandedEvent = null;
    detailData = null;
    await loadEvents(p);
  }

  async function toggleEventDetail(name) {
    if (expandedEvent === name) {
      expandedEvent = null;
      detailData = null;
      if (detailChartInstance) { detailChartInstance.destroy(); detailChartInstance = null; }
      return;
    }
    expandedEvent = name;
    detailData = null;
    detailLoading = true;
    try {
      const res = await analyticsApi.eventDetail(name, period);
      detailData = res.data;
    } catch (err) {
      addToast('Failed to load event details', 'error');
    } finally {
      detailLoading = false;
    }
  }

  onMount(() => loadEvents(period));
</script>

<div class="analytics-events">

  <div class="period-selector">
    {#each PERIODS as p}
      <button
        class="period-btn"
        class:active={period === p.val}
        onclick={() => changePeriod(p.val)}
      >{p.label}</button>
    {/each}
  </div>

  {#if loading}
    <!-- ── Loading Skeleton ─────────────────────────────── -->
    <div class="hero-zone">
      <div class="hero-stats-row">
        {#each [1,2,3,4] as _}
          <div class="hero-stat">
            <div class="skel skel-stat-label"></div>
            <div class="skel skel-stat-number"></div>
            <div class="skel skel-stat-trend"></div>
          </div>
        {/each}
      </div>
      <div class="skel skel-chart"></div>
    </div>

  {:else if !events || (events.total_events === 0 && (!events.top_events || events.top_events.length === 0))}
    <!-- ── Empty State ──────────────────────────────────── -->
    <div class="section-empty">
      No events recorded yet. Events are automatically tracked for outbound clicks, file downloads, and form submissions.
    </div>

  {:else}

    <!-- ── Hero Stats Row ───────────────────────────────── -->
    <div class="hero-zone">
      <div class="hero-stats-row">
        <div class="hero-stat">
          <div class="stat-label">Total Events</div>
          <div class="stat-number">{fmtNum(events.total_events ?? 0)}</div>
          <div class={`stat-trend ${trendClass(events.events_change)}`}>
            {trendBadge(events.events_change)}
          </div>
        </div>
        <div class="hero-stat">
          <div class="stat-label">Unique Sessions</div>
          <div class="stat-number">{fmtNum(events.unique_sessions ?? 0)}</div>
          <div class="stat-trend trend-flat">with events</div>
        </div>
        <div class="hero-stat">
          <div class="stat-label">Auto-tracked</div>
          <div class="stat-number">{fmtNum(events.auto_count ?? 0)}</div>
          <div class="stat-trend trend-flat">clicks, downloads, forms</div>
        </div>
        <div class="hero-stat">
          <div class="stat-label">Custom Events</div>
          <div class="stat-number">{fmtNum(events.custom_count ?? 0)}</div>
          <div class="stat-trend trend-flat">via JS API</div>
        </div>
      </div>

      <!-- ── Chart ────────────────────────────────────────── -->
      {#if events.chart?.length >= 1}
        <div class="chart-wrap">
          <canvas bind:this={chartCanvas}></canvas>
        </div>
      {/if}
    </div>

    <!-- ── Top Events Table ──────────────────────────────── -->
    {#if events.top_events?.length}
      <div class="analytics-section">
        <div class="section-header">
          <span class="split-title">Top Events</span>
        </div>

        <div class="events-table">
          <div class="events-head">
            <span>Event Name</span>
            <span class="col-num">Count</span>
            <span class="col-num">Sessions</span>
            <span class="col-num">Trend</span>
          </div>
          {#each events.top_events as ev (ev.name)}
            <div class="events-row-wrap">
              <button
                class="events-row"
                class:expanded={expandedEvent === ev.name}
                onclick={() => toggleEventDetail(ev.name)}
              >
                <span class="event-name">
                  <svg class="expand-chevron" class:rotated={expandedEvent === ev.name} width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 6 15 12 9 18"/></svg>
                  {ev.name}
                </span>
                <span class="col-num">{fmtNum(ev.count)}</span>
                <span class="col-num">{fmtNum(ev.unique_sessions)}</span>
                <span class="col-num">
                  <span class={trendClass(ev.change)}>{trendBadge(ev.change)}</span>
                </span>
              </button>

              <!-- ── Inline Detail Panel ──────────────────── -->
              {#if expandedEvent === ev.name}
                <div class="event-detail">
                  {#if detailLoading}
                    <div class="detail-loading">
                      <div class="skel" style="width: 100%; height: 120px; border-radius: 6px;"></div>
                    </div>
                  {:else if detailData}
                    <div class="detail-grid">
                      <!-- Mini chart -->
                      <div class="detail-chart-col">
                        <div class="detail-chart-label">Daily count</div>
                        <div class="detail-chart-wrap">
                          <canvas bind:this={detailChartCanvas}></canvas>
                        </div>
                      </div>

                      <!-- Top pages -->
                      <div class="detail-list-col">
                        {#if detailData.top_pages?.length}
                          <div class="detail-list-label">Top Pages</div>
                          {#each detailData.top_pages.slice(0, 5) as pg}
                            <div class="detail-list-row">
                              <span class="detail-path">{pg.path}</span>
                              <span class="detail-count">{fmtNum(pg.count)}</span>
                            </div>
                          {/each}
                        {/if}
                      </div>

                      <!-- Top properties -->
                      <div class="detail-list-col">
                        {#if detailData.top_properties?.length}
                          <div class="detail-list-label">Top Properties</div>
                          {#each detailData.top_properties.slice(0, 5) as prop}
                            <div class="detail-list-row">
                              <span class="detail-prop">
                                <span class="prop-key">{prop.key}</span>
                                <span class="prop-eq">=</span>
                                <span class="prop-val">{prop.value}</span>
                              </span>
                              <span class="detail-count">{fmtNum(prop.count)}</span>
                            </div>
                          {/each}
                        {:else}
                          <div class="detail-list-label">Properties</div>
                          <div class="detail-empty-hint">No properties recorded</div>
                        {/if}
                      </div>
                    </div>
                  {/if}
                </div>
              {/if}
            </div>
          {/each}
        </div>
      </div>
    {/if}

    <!-- ── Recent Events ─────────────────────────────────── -->
    {#if events.recent?.length}
      <div class="analytics-section">
        <div class="section-header">
          <span class="split-title">Recent Events</span>
        </div>

        <div class="recent-list">
          {#each events.recent as r, i (i)}
            <div class="recent-row">
              <div class="recent-main">
                <span class="recent-name">{r.name}</span>
                <span class="recent-path">{r.path}</span>
                <span class="recent-time">{timeAgo(r.created_at)}</span>
              </div>
              {#if formatProps(r.properties).length}
                <div class="recent-props">
                  {#each formatProps(r.properties) as [key, val]}
                    <span class="prop-tag">
                      <span class="prop-key">{key}</span><span class="prop-eq">=</span><span class="prop-val">{val}</span>
                    </span>
                  {/each}
                </div>
              {/if}
            </div>
          {/each}
        </div>
      </div>
    {/if}

  {/if}
</div>

<style>
  /* ── Layout ──────────────────────────────────────────────── */
  .analytics-events {
    /* inherits max-width from parent shell */
  }

  .analytics-events > .period-selector {
    display: flex;
    justify-content: flex-end;
    margin-bottom: 28px;
  }

  /* ── Period Selector ─────────────────────────────────────── */
  .period-selector {
    display: flex;
    gap: 2px;
    background: var(--bg-hover);
    border-radius: var(--radius-sm);
    padding: 3px;
    flex-shrink: 0;
  }

  .period-btn {
    background: none;
    border: none;
    padding: 5px 12px;
    font-size: 13px;
    font-family: inherit;
    color: var(--text-muted);
    cursor: pointer;
    border-radius: 4px;
    white-space: nowrap;
  }
  .period-btn:hover { color: var(--text); }
  .period-btn.active {
    background: var(--bg);
    color: var(--text);
    font-weight: 500;
    box-shadow: 0 1px 3px rgba(45,42,38,0.08);
  }

  /* ── Hero Zone ───────────────────────────────────────────── */
  .hero-zone {
    margin-bottom: 48px;
  }

  .hero-stats-row {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 0;
    border-bottom: 1px solid var(--border-light);
    padding-bottom: 28px;
    margin-bottom: 28px;
  }

  .hero-stat {
    padding-right: 24px;
    border-right: 1px solid var(--border-light);
    padding-left: 24px;
  }
  .hero-stat:first-child { padding-left: 0; }
  .hero-stat:last-child  { border-right: none; }

  .stat-label {
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.07em;
    color: var(--text-muted);
    margin-bottom: 8px;
  }

  .stat-number {
    font-family: var(--font-serif);
    font-size: 44px;
    font-weight: 700;
    color: var(--text);
    line-height: 1;
    letter-spacing: -0.03em;
    margin-bottom: 6px;
  }

  .stat-trend {
    font-size: 12px;
    font-weight: 500;
  }
  .trend-up   { color: var(--moss); }
  .trend-down { color: var(--rust, #C45C3A); }
  .trend-flat { color: var(--text-light); }

  /* ── Chart ───────────────────────────────────────────────── */
  .chart-wrap {
    height: 240px;
    position: relative;
  }

  .section-empty {
    padding: 40px 0;
    font-size: 14px;
    color: var(--text-light);
    text-align: center;
  }

  /* ── Analytics Sections ──────────────────────────────────── */
  .analytics-section {
    border-top: 1px solid var(--border-light);
    padding-top: 32px;
    margin-bottom: 48px;
  }

  .section-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 16px;
  }

  .split-title {
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.07em;
    color: var(--text-muted);
  }

  /* ── Top Events Table ────────────────────────────────────── */
  .events-head {
    display: grid;
    grid-template-columns: 1fr 80px 80px 90px;
    gap: 8px;
    padding-bottom: 10px;
    border-bottom: 1px solid var(--border-light);
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.07em;
    color: var(--text-muted);
  }

  .events-row-wrap {
    border-bottom: 1px solid var(--border-light);
  }
  .events-row-wrap:last-child { border-bottom: none; }

  .events-row {
    display: grid;
    grid-template-columns: 1fr 80px 80px 90px;
    gap: 8px;
    align-items: center;
    padding: 11px 0;
    font-size: 14px;
    width: 100%;
    background: none;
    border: none;
    font-family: inherit;
    cursor: pointer;
    text-align: left;
  }
  .events-row:hover { background: var(--bg-hover); }
  .events-row.expanded { background: var(--bg-hover); }

  .event-name {
    display: flex;
    align-items: center;
    gap: 8px;
    color: var(--text);
    font-weight: 500;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .expand-chevron {
    flex-shrink: 0;
    color: var(--text-muted);
    transition: transform 0.15s;
  }
  .expand-chevron.rotated { transform: rotate(90deg); }

  .col-num { color: var(--text-secondary); text-align: right; }

  /* ── Event Detail Panel ──────────────────────────────────── */
  .event-detail {
    padding: 20px 0 20px 20px;
    background: color-mix(in srgb, var(--bg-hover) 50%, transparent);
    border-top: 1px solid var(--border-light);
  }

  .detail-loading {
    padding: 12px 0;
  }

  .detail-grid {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr;
    gap: 32px;
  }

  .detail-chart-col {
    min-width: 0;
  }

  .detail-chart-label {
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.07em;
    color: var(--text-muted);
    margin-bottom: 10px;
  }

  .detail-chart-wrap {
    height: 120px;
    position: relative;
  }

  .detail-list-col {
    min-width: 0;
  }

  .detail-list-label {
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.07em;
    color: var(--text-muted);
    margin-bottom: 10px;
  }

  .detail-list-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 6px 0;
    border-bottom: 1px solid var(--border-light);
    font-size: 13px;
  }
  .detail-list-row:last-child { border-bottom: none; }

  .detail-path {
    color: var(--text-secondary);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    margin-right: 8px;
  }

  .detail-count {
    color: var(--text);
    font-weight: 500;
    flex-shrink: 0;
  }

  .detail-prop {
    display: flex;
    align-items: center;
    gap: 2px;
    overflow: hidden;
    min-width: 0;
    margin-right: 8px;
  }

  .detail-empty-hint {
    font-size: 13px;
    color: var(--text-light);
  }

  /* ── Property Tags (shared) ──────────────────────────────── */
  .prop-key {
    color: var(--text-muted);
    font-size: 12px;
  }
  .prop-eq {
    color: var(--text-light);
    font-size: 12px;
  }
  .prop-val {
    color: var(--text-secondary);
    font-size: 12px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  /* ── Recent Events ───────────────────────────────────────── */
  .recent-row {
    padding: 12px 0;
    border-bottom: 1px solid var(--border-light);
  }
  .recent-row:last-child { border-bottom: none; }

  .recent-main {
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: 14px;
  }

  .recent-name {
    color: var(--text);
    font-weight: 500;
    white-space: nowrap;
  }

  .recent-path {
    color: var(--text-muted);
    font-size: 13px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    flex: 1;
    min-width: 0;
  }

  .recent-time {
    color: var(--text-light);
    font-size: 12px;
    white-space: nowrap;
    flex-shrink: 0;
  }

  .recent-props {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-top: 6px;
    padding-left: 0;
  }

  .prop-tag {
    display: inline-flex;
    align-items: center;
    gap: 1px;
    background: var(--bg-hover);
    padding: 2px 8px;
    border-radius: 4px;
  }

  /* ── Skeleton ────────────────────────────────────────────── */
  .skel {
    background: var(--bg-hover);
    border-radius: 4px;
    animation: pulse 1.5s ease-in-out infinite;
  }
  .skel-stat-label  { width: 80px;  height: 9px;  margin-bottom: 10px; }
  .skel-stat-number { width: 80px;  height: 36px; margin-bottom: 8px; border-radius: 6px; }
  .skel-stat-trend  { width: 60px;  height: 9px; }
  .skel-chart       { height: 240px; border-radius: var(--radius-sm); margin-top: 28px; }

  @keyframes pulse {
    0%, 100% { opacity: 1; }
    50%       { opacity: 0.4; }
  }

  /* ── Responsive ──────────────────────────────────────────── */
  @media (max-width: 900px) {
    .hero-stats-row { grid-template-columns: repeat(2, 1fr); }
    .period-selector { flex-wrap: wrap; }
    .detail-grid { grid-template-columns: 1fr; }
    .events-head { grid-template-columns: 1fr 60px 60px 70px; }
    .events-row  { grid-template-columns: 1fr 60px 60px 70px; }
  }

  @media (max-width: 600px) {
    .events-head {
      display: none;
    }

    .stat-number {
      font-size: 36px;
    }
  }
</style>
