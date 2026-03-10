<script>
  import { onMount } from 'svelte';
  import { analytics as analyticsApi } from '$lib/api.js';
  import { addToast } from '$lib/stores.js';

  let period = $state('30days');
  let data = $state(null);
  let loading = $state(true);
  let chartCanvas = $state(null);
  let chartInstance = null;

  const PERIODS = [
    { val: '7days',    label: 'Last 7 days' },
    { val: '30days',   label: 'Last 30 days' },
    { val: '90days',   label: 'Last 90 days' },
    { val: '12months', label: 'Last 12 months' },
  ];

  function fmtNum(n) {
    if (n == null) return '—';
    if (n >= 1000000) return (n / 1000000).toFixed(1) + 'M';
    if (n >= 1000) return (n / 1000).toFixed(1) + 'K';
    return String(n);
  }

  function fmtPct(rate) {
    if (rate == null) return '—';
    return Math.round(rate * 100) + '%';
  }

  function trendBadge(change) {
    if (change == null) return '';
    if (change > 0) return `+${change}%`;
    if (change < 0) return `${change}%`;
    return 'same';
  }

  function trendClass(change) {
    if (change == null) return '';
    if (change > 0) return 'trend-up';
    if (change < 0) return 'trend-down';
    return 'trend-flat';
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

  async function buildChart() {
    if (!chartCanvas || !data?.chart) return;
    const chartData = data.chart;
    if (chartData.length < 2) return;

    try {
      const ChartJs = await loadChartJs();
      if (chartInstance) { chartInstance.destroy(); chartInstance = null; }

      const style   = getComputedStyle(document.documentElement);
      const textMuted = style.getPropertyValue('--text-muted').trim() || '#8A857D';
      const forest  = '#2D5A47';
      const isDark  = document.documentElement.classList.contains('dark');
      const ctx     = chartCanvas.getContext('2d');

      const labels = chartData.map(d => {
        const dt = new Date(d.date + 'T00:00:00');
        return dt.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
      });

      const gradient = ctx.createLinearGradient(0, 0, 0, chartCanvas.height || 240);
      gradient.addColorStop(0, 'rgba(45, 90, 71, 0.10)');
      gradient.addColorStop(1, 'rgba(45, 90, 71, 0.00)');

      chartInstance = new ChartJs(ctx, {
        type: 'line',
        data: {
          labels,
          datasets: [{
            label: 'Searches',
            data: chartData.map(d => d.searches),
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
    }
  }

  $effect(() => {
    const canvas = chartCanvas;
    const chartData = data?.chart;
    if (!canvas || !chartData) return;
    buildChart();
    return () => {
      if (chartInstance) { chartInstance.destroy(); chartInstance = null; }
    };
  });

  // ── Data loading ───────────────────────────────────────────
  async function loadData(p = '30days') {
    loading = true;
    try {
      const res = await analyticsApi.search(p);
      data = res.data;
    } catch (err) {
      addToast('Failed to load search analytics', 'error');
    } finally {
      loading = false;
    }
  }

  async function changePeriod(p) {
    period = p;
    await loadData(p);
  }

  onMount(() => loadData(period));
</script>

<div class="analytics-search">
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
    <div class="hero-zone">
      <div class="hero-stats-row">
        {#each [1,2,3] as _}
          <div class="hero-stat">
            <div class="skel skel-stat-label"></div>
            <div class="skel skel-stat-number"></div>
            <div class="skel skel-stat-trend"></div>
          </div>
        {/each}
      </div>
      <div class="skel skel-chart"></div>
    </div>

  {:else if !data || data.total_searches === 0}
    <div class="section-empty">
      <p>No search data yet.</p>
      <p class="empty-hint">Search tracking starts automatically when visitors use URL parameters like <code>?q=</code>, <code>?search=</code>, or <code>?s=</code>. You can also call <code>outpost.trackSearch(query, resultsCount)</code> from your theme's JavaScript.</p>
    </div>

  {:else}
    <div class="hero-zone">
      <div class="hero-stats-row">
        <div class="hero-stat">
          <div class="stat-label">Total Searches</div>
          <div class="stat-number">{fmtNum(data.total_searches)}</div>
          <div class={`stat-trend ${trendClass(data.total_searches_change)}`}>
            {trendBadge(data.total_searches_change)}
          </div>
        </div>
        <div class="hero-stat">
          <div class="stat-label">Unique Queries</div>
          <div class="stat-number">{fmtNum(data.unique_queries)}</div>
          <div class="stat-trend trend-flat">distinct terms</div>
        </div>
        <div class="hero-stat">
          <div class="stat-label">Click-through Rate</div>
          <div class="stat-number">{fmtPct(data.click_rate)}</div>
          <div class="stat-trend trend-flat">searches with a click</div>
        </div>
      </div>

      {#if data.total_searches > 0}
        <div class="chart-wrap">
          <canvas bind:this={chartCanvas}></canvas>
        </div>
      {/if}
    </div>

    <!-- Top Queries + Zero Results -->
    <div class="dash-split">
      <div class="split-left">
        <div class="split-header">
          <span class="split-title">Top Queries</span>
        </div>
        {#if data.top_queries?.length}
          <div class="search-table">
            <div class="search-head">
              <span>Query</span>
              <span class="col-num">Count</span>
              <span class="col-num">Avg Results</span>
            </div>
            {#each data.top_queries as q}
              <div class="search-row">
                <span class="query-text">{q.query}</span>
                <span class="col-num">{fmtNum(q.count)}</span>
                <span class="col-num">{q.avg_results ?? '—'}</span>
              </div>
            {/each}
          </div>
        {:else}
          <div class="section-empty">No queries recorded yet.</div>
        {/if}
      </div>

      <div class="split-right">
        <div class="split-header">
          <span class="split-title zero-title">Zero-Result Queries</span>
        </div>
        {#if data.zero_result_queries?.length}
          <div class="search-table">
            <div class="search-head">
              <span>Query</span>
              <span class="col-num">Count</span>
            </div>
            {#each data.zero_result_queries as q}
              <div class="search-row zero-row">
                <span class="query-text">{q.query}</span>
                <span class="col-num">{fmtNum(q.count)}</span>
              </div>
            {/each}
          </div>
        {:else}
          <div class="section-empty">No zero-result queries — great!</div>
        {/if}
      </div>
    </div>

    <!-- Top Clicked Results -->
    {#if data.top_clicked?.length}
      <div class="analytics-section">
        <div class="section-header">
          <span class="split-title">Top Clicked Results</span>
        </div>
        <div class="search-table">
          <div class="search-head clicked-head">
            <span>Page</span>
            <span class="col-num">Clicks</span>
          </div>
          {#each data.top_clicked as c}
            <div class="search-row clicked-head">
              <span class="query-text">{c.clicked_path}</span>
              <span class="col-num">{fmtNum(c.count)}</span>
            </div>
          {/each}
        </div>
      </div>
    {/if}
  {/if}
</div>

<style>
  .analytics-search {
    padding-top: 0;
  }

  .analytics-search > .period-selector {
    display: flex;
    justify-content: flex-end;
    margin-bottom: 28px;
  }

  .period-selector {
    display: flex;
    gap: 2px;
    background: var(--bg-hover);
    border-radius: 6px;
    padding: 2px;
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
  }

  /* ── Hero Stats ────────────────────────────────────────────── */
  .hero-zone {
    margin-bottom: 48px;
  }

  .hero-stats-row {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 0;
    align-items: flex-start;
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
    letter-spacing: 0.05em;
    color: var(--text-muted);
    margin-bottom: 8px;
  }

  .stat-number {
    font-family: var(--font-serif);
    font-size: 44px;
    font-weight: 700;
    letter-spacing: -0.02em;
    color: var(--text);
    line-height: 1;
    margin-bottom: 6px;
  }

  .stat-trend {
    font-size: 12px;
    font-weight: 500;
  }
  .trend-up   { color: var(--forest); }
  .trend-down { color: var(--error); }
  .trend-flat { color: var(--text-muted); }

  /* ── Chart ─────────────────────────────────────────────────── */
  .chart-wrap {
    height: 240px;
    position: relative;
  }

  /* ── Split Layout ──────────────────────────────────────────── */
  .dash-split {
    display: grid;
    grid-template-columns: 3fr 2fr;
    border-top: 1px solid var(--border-light);
    margin-bottom: 48px;
  }

  .split-left {
    padding-right: 32px;
    padding-top: 32px;
  }

  .split-right {
    border-left: 1px solid var(--border-light);
    padding-left: 32px;
    padding-top: 32px;
  }

  .split-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
  }

  .split-title {
    font-size: 15px;
    font-weight: 600;
    color: var(--text);
  }

  .zero-title {
    color: var(--error);
  }

  /* ── Section ───────────────────────────────────────────────── */
  .analytics-section {
    border-top: 1px solid var(--border-light);
    padding-top: 32px;
    margin-bottom: 48px;
  }

  .section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
  }

  /* ── Search Table ──────────────────────────────────────────── */
  .search-head {
    display: grid;
    grid-template-columns: 1fr 70px 80px;
    gap: 8px;
    padding: 10px 0;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--text-muted);
  }

  .search-row {
    display: grid;
    grid-template-columns: 1fr 70px 80px;
    gap: 8px;
    padding: 10px 0;
    border-bottom: 1px solid var(--border-light);
    font-size: 14px;
  }
  .search-row:last-child { border-bottom: none; }

  .clicked-head {
    grid-template-columns: 1fr 70px;
  }

  .query-text {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    color: var(--text);
    font-weight: 500;
  }

  .zero-row .query-text {
    color: var(--error);
  }

  .col-num {
    color: var(--text-secondary);
    text-align: right;
  }

  /* ── Empty State ───────────────────────────────────────────── */
  .section-empty {
    padding: 40px 0;
    font-size: 14px;
    color: var(--text-light);
    text-align: center;
  }

  .empty-hint {
    font-size: 13px;
    color: var(--text-muted);
    margin-top: 8px;
    max-width: 500px;
    margin-left: auto;
    margin-right: auto;
    line-height: 1.5;
  }

  .empty-hint code {
    background: var(--bg-hover);
    padding: 1px 5px;
    border-radius: 3px;
    font-size: 12px;
  }

  /* ── Skeletons ─────────────────────────────────────────────── */
  .skel {
    background: var(--bg-hover);
    border-radius: 6px;
    animation: pulse 1.5s ease-in-out infinite;
  }
  .skel-stat-label  { width: 80px; height: 12px; margin-bottom: 10px; }
  .skel-stat-number { width: 100px; height: 40px; margin-bottom: 8px; }
  .skel-stat-trend  { width: 60px; height: 12px; }
  .skel-chart       { height: 240px; margin-top: 20px; }

  @keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.5; }
  }

  /* ── Responsive ────────────────────────────────────────────── */
  @media (max-width: 900px) {
    .hero-stats-row { grid-template-columns: repeat(2, 1fr); }
    .dash-split { grid-template-columns: 1fr; }
    .split-left  { padding-right: 0; }
    .split-right { border-left: none; border-top: 1px solid var(--border-light); padding-left: 0; padding-top: 32px; margin-top: 32px; }
    .period-selector { flex-wrap: wrap; }
  }

  @media (max-width: 600px) {
    .hero-stats-row {
      grid-template-columns: 1fr;
      gap: 20px;
    }
    .hero-stat { border-right: none; padding-left: 0; }
    .stat-number { font-size: 36px; }
  }
</style>
