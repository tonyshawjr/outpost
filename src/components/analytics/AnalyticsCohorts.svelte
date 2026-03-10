<script>
  import { onMount } from 'svelte';
  import { analytics as analyticsApi } from '$lib/api.js';
  import { addToast } from '$lib/stores.js';

  let period = $state('30days');
  let data = $state(null);
  let loading = $state(true);
  let expandedCohort = $state(null);
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

  let topInsight = $derived.by(() => {
    if (!data?.cohorts) return null;
    const recent = data.cohorts.find(c => c.label === 'Last 30 days');
    if (!recent || recent.view_share === 0) return null;
    return `Content from the last 30 days drives ${recent.view_share}% of traffic`;
  });

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
    if (!chartCanvas || !data?.cohorts) return;
    const cohorts = data.cohorts.filter(c => c.total_views > 0);
    if (cohorts.length === 0) return;

    try {
      const ChartJs = await loadChartJs();
      if (chartInstance) { chartInstance.destroy(); chartInstance = null; }

      const style   = getComputedStyle(document.documentElement);
      const textMuted = style.getPropertyValue('--text-muted').trim() || '#8A857D';
      const isDark  = document.documentElement.classList.contains('dark');
      const ctx     = chartCanvas.getContext('2d');

      const colors = ['#2D5A47', '#3D7A5F', '#6B8F71', '#8FB996', '#B5D4B8'];

      chartInstance = new ChartJs(ctx, {
        type: 'bar',
        data: {
          labels: cohorts.map(c => c.label),
          datasets: [{
            label: 'Views',
            data: cohorts.map(c => c.total_views),
            backgroundColor: cohorts.map((_, i) => colors[i % colors.length]),
            borderRadius: 4,
            barPercentage: 0.7,
          }],
        },
        options: {
          indexAxis: 'y',
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: { display: false },
            tooltip: {
              backgroundColor: isDark ? '#F5F3EF' : '#2D2A26',
              titleColor:       isDark ? '#2D2A26' : '#FFF',
              bodyColor:        isDark ? '#2D2A26' : '#FFF',
              borderWidth: 0, cornerRadius: 8, padding: 10,
            },
          },
          scales: {
            x: {
              grid: { color: isDark ? 'rgba(255,255,255,0.06)' : 'rgba(229,225,218,0.6)', drawTicks: false },
              ticks: { color: textMuted, font: { family: 'Inter', size: 12 } },
              border: { display: false },
              beginAtZero: true,
            },
            y: {
              grid: { display: false },
              ticks: { color: textMuted, font: { family: 'Inter', size: 13 } },
              border: { display: false },
            },
          },
        },
      });
    } catch (err) {
      console.error('Chart.js failed:', err);
    }
  }

  $effect(() => {
    const canvas = chartCanvas;
    const cohorts = data?.cohorts;
    if (!canvas || !cohorts) return;
    buildChart();
    return () => {
      if (chartInstance) { chartInstance.destroy(); chartInstance = null; }
    };
  });

  // ── Data loading ───────────────────────────────────────────
  async function loadData(p = '30days') {
    loading = true;
    try {
      const res = await analyticsApi.cohorts(p);
      data = res.data;
    } catch (err) {
      addToast('Failed to load content cohorts', 'error');
    } finally {
      loading = false;
    }
  }

  async function changePeriod(p) {
    period = p;
    expandedCohort = null;
    await loadData(p);
  }

  function toggleCohort(label) {
    expandedCohort = expandedCohort === label ? null : label;
  }

  onMount(() => loadData(period));
</script>

<div class="analytics-cohorts">
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
      <div class="skel skel-chart"></div>
      <div class="skel skel-table" style="margin-top: 28px;"></div>
    </div>

  {:else if !data || data.total_views === 0}
    <div class="section-empty">
      No traffic data to group by content age yet. Start publishing pages and drive some traffic.
    </div>

  {:else}
    <!-- Insight callout -->
    {#if topInsight}
      <div class="insight-callout">{topInsight}</div>
    {/if}

    <!-- Chart -->
    <div class="chart-wrap">
      <canvas bind:this={chartCanvas}></canvas>
    </div>

    <!-- Cohort Table -->
    <div class="cohort-table">
      <div class="cohort-head">
        <span>Cohort</span>
        <span class="col-num">Pages</span>
        <span class="col-num">Views</span>
        <span class="col-num">Avg/Page</span>
        <span class="col-num">Share</span>
      </div>
      {#each data.cohorts as cohort}
        {#if cohort.page_count > 0}
          <button
            class="cohort-row"
            class:expanded={expandedCohort === cohort.label}
            onclick={() => toggleCohort(cohort.label)}
          >
            <span class="cohort-label">
              <span class="expand-arrow">{expandedCohort === cohort.label ? '▾' : '▸'}</span>
              {cohort.label}
            </span>
            <span class="col-num">{fmtNum(cohort.page_count)}</span>
            <span class="col-num">{fmtNum(cohort.total_views)}</span>
            <span class="col-num">{fmtNum(cohort.avg_views_per_page)}</span>
            <span class="col-num">{cohort.view_share}%</span>
          </button>
          {#if expandedCohort === cohort.label && cohort.top_pages?.length}
            <div class="cohort-detail">
              {#each cohort.top_pages as page}
                <div class="detail-row">
                  <span class="detail-path">{page.title || page.path}</span>
                  <span class="col-num">{fmtNum(page.views)}</span>
                </div>
              {/each}
            </div>
          {/if}
        {:else}
          <div class="cohort-row empty-cohort">
            <span class="cohort-label">{cohort.label}</span>
            <span class="col-num">0</span>
            <span class="col-num">0</span>
            <span class="col-num">—</span>
            <span class="col-num">0%</span>
          </div>
        {/if}
      {/each}
    </div>
  {/if}
</div>

<style>
  .analytics-cohorts {
    padding-top: 0;
  }

  .analytics-cohorts > .period-selector {
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

  /* ── Insight Callout ───────────────────────────────────────── */
  .insight-callout {
    background: var(--bg-hover);
    border-radius: 8px;
    padding: 14px 20px;
    font-size: 14px;
    font-weight: 500;
    color: var(--forest);
    margin-bottom: 28px;
  }

  /* ── Chart ─────────────────────────────────────────────────── */
  .chart-wrap {
    height: 200px;
    position: relative;
    margin-bottom: 36px;
  }

  /* ── Cohort Table ──────────────────────────────────────────── */
  .cohort-head {
    display: grid;
    grid-template-columns: 1fr 70px 70px 80px 60px;
    gap: 8px;
    padding: 10px 0;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--text-muted);
    border-bottom: 1px solid var(--border-light);
  }

  .cohort-row {
    display: grid;
    grid-template-columns: 1fr 70px 70px 80px 60px;
    gap: 8px;
    padding: 12px 0;
    border: none;
    border-bottom: 1px solid var(--border-light);
    background: none;
    font-size: 14px;
    font-family: inherit;
    cursor: pointer;
    width: 100%;
    text-align: left;
  }
  .cohort-row:hover { background: var(--bg-hover); }
  .cohort-row:last-child { border-bottom: none; }

  .empty-cohort {
    cursor: default;
    color: var(--text-muted);
  }
  .empty-cohort:hover { background: none; }

  .cohort-label {
    font-weight: 500;
    color: var(--text);
    display: flex;
    align-items: center;
    gap: 6px;
  }

  .expand-arrow {
    font-size: 10px;
    color: var(--text-muted);
    width: 12px;
    display: inline-block;
  }

  .col-num {
    color: var(--text-secondary);
    text-align: right;
  }

  /* ── Detail Rows ───────────────────────────────────────────── */
  .cohort-detail {
    padding: 0 0 8px 28px;
    border-bottom: 1px solid var(--border-light);
  }

  .detail-row {
    display: grid;
    grid-template-columns: 1fr 70px;
    gap: 8px;
    padding: 6px 0;
    font-size: 13px;
  }

  .detail-path {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    color: var(--text);
  }

  /* ── Empty State ───────────────────────────────────────────── */
  .section-empty {
    padding: 40px 0;
    font-size: 14px;
    color: var(--text-light);
    text-align: center;
  }

  /* ── Skeletons ─────────────────────────────────────────────── */
  .skel {
    background: var(--bg-hover);
    border-radius: 6px;
    animation: pulse 1.5s ease-in-out infinite;
  }
  .skel-chart { height: 200px; }
  .skel-table { height: 200px; }

  @keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.5; }
  }

  /* ── Responsive ────────────────────────────────────────────── */
  @media (max-width: 600px) {
    .cohort-head, .cohort-row {
      grid-template-columns: 1fr 50px 50px 60px;
    }
    .cohort-head span:last-child,
    .cohort-row span:last-child {
      display: none;
    }
    .period-selector { flex-wrap: wrap; }
  }
</style>
