<script>
  import { onMount } from 'svelte';
  import { dashboard as dashboardApi, cache as cacheApi } from '$lib/api.js';
  import { user, navigate, addToast } from '$lib/stores.js';
  import { timeAgo, formatDateOnly } from '$lib/utils.js';
  import EmptyState from '$components/EmptyState.svelte';
  import GettingStarted from '$components/GettingStarted.svelte';

  let currentUser = $derived($user);

  // ── Layout: full-width, no right sidebar ──────────────────
  $effect(() => {
    const layout = document.querySelector('.app-layout');
    if (layout) layout.classList.add('no-right-sidebar');
    return () => {
      if (layout) layout.classList.remove('no-right-sidebar');
    };
  });

  // ── State ──────────────────────────────────────────────────
  let stats = $state(null);
  let activity = $state([]);
  let loading = $state(true);
  let chartPeriod = $state('30days');
  let showSectionsPanel = $state(false);
  let chartCanvas = $state(null);
  let chartInstance = null;

  // ── Sections (localStorage) ────────────────────────────────
  const SECTIONS_KEY = 'outpost-dashboard-sections';
  const SECTION_DEFAULTS = { growth_chart: true, activity_feed: true };

  function loadSections() {
    try {
      const saved = localStorage.getItem(SECTIONS_KEY);
      return saved ? { ...SECTION_DEFAULTS, ...JSON.parse(saved) } : { ...SECTION_DEFAULTS };
    } catch {
      return { ...SECTION_DEFAULTS };
    }
  }

  let sections = $state(loadSections());

  function toggleSection(key) {
    sections[key] = !sections[key];
    localStorage.setItem(SECTIONS_KEY, JSON.stringify(sections));
  }

  // ── Derived ────────────────────────────────────────────────
  let hasMemberData = $derived(stats != null && stats.totals.members_total > 0);

  let isEmptyState = $derived(
    stats != null &&
    stats.totals.pages === 0 &&
    stats.totals.collection_items === 0 &&
    stats.totals.members_total === 0
  );

  // Hero: members if any exist, otherwise collection items
  let heroValue  = $derived(hasMemberData ? stats?.totals.members_total  : stats?.totals.collection_items);
  let heroLabel  = $derived(hasMemberData ? 'Members'                    : 'Collection Items');
  let heroTrend  = $derived(hasMemberData ? stats?.trends.members_this_month : stats?.trends.items_this_month);

  // ── Helpers ────────────────────────────────────────────────
  function greeting() {
    const h = new Date().getHours();
    if (h < 12) return 'Good morning';
    if (h < 17) return 'Good afternoon';
    return 'Good evening';
  }

  function formatHeaderDate() {
    return new Date().toLocaleDateString('en-US', {
      weekday: 'long', month: 'long', day: 'numeric',
    });
  }

  function activityDot(type) {
    if (type === 'member')  return 'var(--sage)';
    if (type === 'content') return 'var(--forest)';
    if (type === 'media')   return 'var(--amber)';
    return 'var(--text-light)';
  }

  function statusBadgeClass(status) {
    if (status === 'published') return 'badge-published';
    if (status === 'scheduled') return 'badge-scheduled';
    return 'badge-draft';
  }

  // ── Chart.js ───────────────────────────────────────────────
  async function loadChartJs() {
    if (window.Chart) return window.Chart;
    return new Promise((resolve, reject) => {
      const script = document.createElement('script');
      script.src = 'https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js';
      script.onload  = () => resolve(window.Chart);
      script.onerror = reject;
      document.head.appendChild(script);
    });
  }

  function hasEnoughChartData() {
    if (!stats) return false;
    if (hasMemberData) {
      const growth = stats.growth || [];
      if (growth.length < 3) return false;
      const vals = growth.map(d => d.total);
      return vals.some(v => v !== vals[0]);
    }
    return (stats.content_activity || []).length >= 1;
  }

  let chartHasData = $derived(hasEnoughChartData());

  async function buildChart() {
    if (!chartCanvas || !stats) return;
    const growth         = stats.growth          || [];
    const contentActivity = stats.content_activity || [];
    const useMemberChart  = hasMemberData && growth.length >= 2;
    const useContentChart = !hasMemberData && contentActivity.length >= 2;
    if (!useMemberChart && !useContentChart) return;

    try {
      const ChartJs = await loadChartJs();
      if (chartInstance) { chartInstance.destroy(); chartInstance = null; }

      const style    = getComputedStyle(document.documentElement);
      const textMuted = style.getPropertyValue('--text-muted').trim() || '#8A857D';
      const forest   = '#2D5A47';
      const clay     = '#C4785C';
      const isDark   = document.documentElement.classList.contains('dark');
      const ctx      = chartCanvas.getContext('2d');

      if (useMemberChart) {
        const gradient = ctx.createLinearGradient(0, 0, 0, chartCanvas.height || 240);
        gradient.addColorStop(0, 'rgba(45, 90, 71, 0.10)');
        gradient.addColorStop(1, 'rgba(45, 90, 71, 0.00)');

        const labels = growth.map(d => {
          const dt = new Date(d.date + 'T00:00:00');
          return dt.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
        });

        const datasets = [{
          label: 'Total Members',
          data: growth.map(d => d.total),
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
        }];

        if (growth.some(d => d.paid > 0)) {
          datasets.push({
            label: 'Paid Members',
            data: growth.map(d => d.paid),
            borderColor: clay,
            backgroundColor: 'transparent',
            fill: false,
            tension: 0.4,
            pointRadius: 0,
            pointHoverRadius: 4,
            pointHoverBackgroundColor: clay,
            pointHoverBorderColor: '#fff',
            pointHoverBorderWidth: 2,
            borderWidth: 1.5,
          });
        }

        chartInstance = new ChartJs(ctx, {
          type: 'line',
          data: { labels, datasets },
          options: buildChartOptions(textMuted, isDark),
        });
      } else {
        const labels = contentActivity.map(d => {
          const dt = new Date(d.date + 'T00:00:00');
          return dt.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
        });

        chartInstance = new ChartJs(ctx, {
          type: 'bar',
          data: {
            labels,
            datasets: [{
              label: 'Pageviews',
              data: contentActivity.map(d => d.count),
              backgroundColor: 'rgba(45, 90, 71, 0.10)',
              borderColor: forest,
              borderWidth: 1.5,
              borderRadius: 3,
            }],
          },
          options: buildChartOptions(textMuted, isDark),
        });
      }
    } catch (err) {
      console.error('Chart.js failed to load:', err);
    }
  }

  function buildChartOptions(textMuted, isDark) {
    return {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        tooltip: {
          backgroundColor: isDark ? '#F5F3EF' : '#2D2A26',
          titleColor:       isDark ? '#2D2A26' : '#FFFFFF',
          bodyColor:        isDark ? '#2D2A26' : '#FFFFFF',
          borderWidth: 0,
          cornerRadius: 8,
          padding: 10,
        },
      },
      scales: {
        x: {
          grid:   { display: false },
          ticks:  { color: textMuted, font: { family: 'Inter', size: 12 }, maxTicksLimit: 8 },
          border: { display: false },
        },
        y: {
          grid: {
            color: isDark ? 'rgba(255,255,255,0.06)' : 'rgba(229,225,218,0.6)',
            drawTicks: false,
          },
          ticks:       { display: false },   // guide lines only, no labels
          border:      { display: false },
          beginAtZero: true,
        },
      },
      interaction: { mode: 'index', intersect: false },
    };
  }

  $effect(() => {
    const canvas = chartCanvas;
    const growth = stats?.growth;
    const contentActivity = stats?.content_activity;
    if (!canvas || (!growth && !contentActivity)) return;
    if (!chartHasData) return;
    buildChart();
    return () => {
      if (chartInstance) { chartInstance.destroy(); chartInstance = null; }
    };
  });

  // ── Data loading ───────────────────────────────────────────
  async function loadData(period = '30days') {
    try {
      const [statsRes, activityRes] = await Promise.all([
        dashboardApi.stats(period),
        dashboardApi.activity(),
      ]);
      stats    = statsRes.data;
      activity = activityRes.data || [];
    } catch (err) {
      addToast(err.message || 'Failed to load dashboard', 'error');
    }
  }

  async function changePeriod(period) {
    chartPeriod = period;
    try {
      const res = await dashboardApi.stats(period);
      stats = { ...stats, growth: res.data.growth, period: res.data.period };
    } catch (err) {
      addToast(err.message, 'error');
    }
  }

  async function clearCache() {
    try {
      await cacheApi.clear();
      addToast('Cache cleared');
    } catch (err) {
      addToast(err.message, 'error');
    }
  }

  function getSiteUrl() {
    const base = window.location.origin + window.location.pathname;
    return base.replace(/\/outpost\/.*$/, '/');
  }

  onMount(async () => {
    await loadData(chartPeriod);
    loading = false;
  });
</script>

<div class="dashboard">

  <!-- ── Page Header ──────────────────────────────────────── -->
  <div class="dash-header">
    <div class="dash-header-left">
      <h1 class="dash-greeting">
        {greeting()}, {currentUser?.display_name || currentUser?.username || 'there'}
      </h1>
      <p class="dash-subtitle">{formatHeaderDate()}</p>
    </div>

    <div class="dash-header-right">
      <div class="dash-actions">
        <button class="btn-primary-sm" onclick={() => navigate('page-editor', {})}>
          + New Page
        </button>
        <button class="btn-ghost-sm" onclick={clearCache}>
          Clear Cache
        </button>
        <a class="btn-ghost-sm" href={getSiteUrl()} target="_blank" rel="noopener">
          View Site
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="vertical-align: middle; margin-left: 2px;"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
        </a>
      </div>

      <div class="sections-wrap">
        <button
          class="gear-btn"
          onclick={(e) => { e.stopPropagation(); showSectionsPanel = !showSectionsPanel; }}
          title="Configure dashboard sections"
          aria-label="Configure sections"
        >
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14M4.93 4.93a10 10 0 0 0 0 14.14M19.07 4.93l-2.12 2.12M4.93 4.93l2.12 2.12M19.07 19.07l-2.12-2.12M4.93 19.07l2.12-2.12"/></svg>
        </button>

        {#if showSectionsPanel}
          <!-- svelte-ignore a11y_click_events_have_key_events -->
          <!-- svelte-ignore a11y_no_static_element_interactions -->
          <div class="sections-panel" onclick={(e) => e.stopPropagation()}>
            <div class="sections-panel-header">
              <span>Dashboard Sections</span>
              <button class="panel-close" onclick={() => showSectionsPanel = false}>×</button>
            </div>
            {#each [
              { key: 'growth_chart',  label: 'Growth chart' },
              { key: 'activity_feed', label: 'Activity feed' },
            ] as sec}
              <div class="section-toggle-row">
                <label class="toggle-label">
                  <input
                    type="checkbox"
                    checked={sections[sec.key]}
                    onchange={() => toggleSection(sec.key)}
                  />
                  <span class="toggle-track"><span class="toggle-thumb"></span></span>
                  <span>{sec.label}</span>
                </label>
              </div>
            {/each}
          </div>
        {/if}
      </div>
    </div>
  </div>

  <!-- ── Loading Skeleton ─────────────────────────────────── -->
  {#if loading}
    <div class="hero-zone">
      <div class="skel skel-hero-label"></div>
      <div class="skel skel-hero-number"></div>
      <div class="skel skel-chart"></div>
    </div>

  <!-- ── Empty State ──────────────────────────────────────── -->
  {:else if isEmptyState}
    <EmptyState
      title="Welcome to Outpost"
      description="Activate a theme to get started. Pages are created automatically from your theme templates."
      ctaLabel="Choose a theme"
      ctaAction={() => navigate('themes')}
      secondaryLabel="Explore collections"
      secondaryAction={() => navigate('collections')}
    />

  <!-- ── Full Dashboard ───────────────────────────────────── -->
  {:else}

    <GettingStarted />

    <!-- Hero zone: dominant stat + chart as one visual unit -->
    <div class="hero-zone">
      <div class="hero-top">
        <span class="hero-label">{heroLabel}</span>
        {#if hasMemberData}
          <div class="period-tabs">
            {#each [['30days', '30 days'], ['90days', '90 days'], ['1year', '1 year']] as [val, label]}
              <button
                class="period-tab"
                class:active={chartPeriod === val}
                onclick={() => changePeriod(val)}
              >{label}</button>
            {/each}
          </div>
        {/if}
      </div>

      <div class="hero-number-row">
        <span class="hero-number">{heroValue}</span>
        {#if heroTrend > 0}
          <span class="hero-trend">↑ +{heroTrend} this month</span>
        {/if}
      </div>

      {#if sections.growth_chart}
        <div class="chart-wrap">
          {#if chartHasData}
            <canvas bind:this={chartCanvas}></canvas>
          {:else}
            <div class="chart-empty">
              Not enough data yet — check back as your site grows.
            </div>
          {/if}
        </div>
      {/if}
    </div>

    <!-- Split: Recent Content (60%) + Overview (40%) -->
    <div class="dash-split">

      <!-- Left: recent content table -->
      <div class="split-left">
        <div class="split-header">
          <span class="split-title">Recent Content</span>
          <button class="view-all-link" onclick={() => navigate('collections')}>View all →</button>
        </div>

        {#if stats.recent_items?.length}
          <div class="content-table">
            <div class="content-table-head">
              <span>Title</span>
              <span>Status</span>
              <span>Date</span>
            </div>
            {#each stats.recent_items as item (item.id)}
              <div class="content-table-row">
                <span class="content-item-title">{item.title || item.slug}</span>
                <span class="badge {statusBadgeClass(item.status)}">{item.status}</span>
                <span class="content-item-date">{formatDateOnly(item.status === 'published' ? item.published_at : item.updated_at)}</span>
              </div>
            {/each}
          </div>
        {:else}
          <div class="empty-inline">No content yet</div>
        {/if}
      </div>

      <!-- Right: secondary stat rows -->
      <div class="split-right">
        <div class="split-header">
          <span class="split-title">Overview</span>
        </div>

        <div class="stat-list">
          <div class="stat-list-row">
            <span class="stat-list-label">Pages</span>
            <span class="stat-list-value">{stats.totals.pages}</span>
          </div>
          <div class="stat-list-row">
            <span class="stat-list-label">Media files</span>
            <span class="stat-list-value">{stats.totals.media_count}</span>
          </div>
          {#if stats.totals.media_size_mb > 0}
            <div class="stat-list-row">
              <span class="stat-list-label">Storage used</span>
              <span class="stat-list-value">{stats.totals.media_size_mb} MB</span>
            </div>
          {/if}
          {#if hasMemberData}
            <div class="stat-list-row">
              <span class="stat-list-label">Free members</span>
              <span class="stat-list-value">{stats.totals.members_free}</span>
            </div>
            <div class="stat-list-row">
              <span class="stat-list-label">Paid members</span>
              <span class="stat-list-value">{stats.totals.members_paid}</span>
            </div>
            {#if stats.trends.members_this_month > 0}
              <div class="stat-list-row">
                <span class="stat-list-label">New this month</span>
                <span class="stat-list-value positive">+{stats.trends.members_this_month}</span>
              </div>
            {/if}
          {:else}
            <div class="stat-list-row">
              <span class="stat-list-label">Collection items</span>
              <span class="stat-list-value">{stats.totals.collection_items}</span>
            </div>
            {#if stats.trends.items_this_month > 0}
              <div class="stat-list-row">
                <span class="stat-list-label">Published this month</span>
                <span class="stat-list-value positive">+{stats.trends.items_this_month}</span>
              </div>
            {/if}
          {/if}
        </div>
      </div>
    </div>

    <!-- Activity Feed -->
    {#if sections.activity_feed}
      <div class="activity-section">
        <div class="split-header">
          <span class="split-title">Recent Activity</span>
        </div>

        {#if activity.length}
          <div class="activity-list">
            {#each activity as event (event.created_at + event.description)}
              <div class="activity-row">
                <span class="activity-dot" style="background: {activityDot(event.type)};"></span>
                <span class="activity-desc">{event.description}</span>
                <span class="activity-time">{timeAgo(event.created_at)}</span>
              </div>
            {/each}
          </div>
        {:else}
          <div class="empty-inline">No recent activity</div>
        {/if}
      </div>
    {/if}

  {/if}
</div>

{#if showSectionsPanel}
  <!-- svelte-ignore a11y_click_events_have_key_events -->
  <!-- svelte-ignore a11y_no_static_element_interactions -->
  <div class="panel-backdrop" onclick={() => showSectionsPanel = false}></div>
{/if}

<style>
  /* ── Layout ──────────────────────────────────────────────── */
  .dashboard {
    max-width: var(--content-width-wide);
  }

  /* ── Page Header ─────────────────────────────────────────── */
  .dash-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 24px;
    margin-bottom: 48px;
  }

  .dash-greeting {
    font-family: var(--font-serif);
    font-size: 28px;
    font-weight: 600;
    color: var(--text);
    margin: 0 0 4px;
    letter-spacing: -0.01em;
  }

  .dash-subtitle {
    font-size: 14px;
    color: var(--text-muted);
    margin: 0;
  }

  .dash-header-right {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-shrink: 0;
    padding-top: 4px;
  }

  .dash-actions {
    display: flex;
    align-items: center;
    gap: 8px;
  }

  /* ── Buttons ─────────────────────────────────────────────── */
  .btn-primary-sm {
    height: 34px;
    padding: 0 14px;
    background: var(--forest);
    color: #fff;
    border: none;
    border-radius: var(--radius-sm);
    font-size: 13px;
    font-weight: 500;
    cursor: pointer;
    font-family: inherit;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    letter-spacing: 0.01em;
  }
  .btn-primary-sm:hover { opacity: 0.88; }

  .btn-ghost-sm {
    height: 34px;
    padding: 0 12px;
    background: transparent;
    color: var(--text-secondary);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    font-size: 13px;
    font-weight: 500;
    cursor: pointer;
    font-family: inherit;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 4px;
  }
  .btn-ghost-sm:hover { background: var(--bg-hover); }

  /* ── Gear / Sections Panel ───────────────────────────────── */
  .sections-wrap { position: relative; }

  .gear-btn {
    width: 34px;
    height: 34px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: transparent;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    color: var(--text-muted);
    cursor: pointer;
    padding: 0;
  }
  .gear-btn:hover { background: var(--bg-hover); color: var(--text); }

  .sections-panel {
    position: absolute;
    top: calc(100% + 8px);
    right: 0;
    width: 210px;
    background: var(--bg);
    border: 1px solid var(--border-light);
    border-radius: var(--radius-md);
    box-shadow: 0 4px 24px rgba(45,42,38,0.10), 0 1px 4px rgba(45,42,38,0.06);
    z-index: 100;
    padding: 4px 0 8px;
  }

  .sections-panel-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 16px 10px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--text-muted);
    border-bottom: 1px solid var(--border-light);
    margin-bottom: 4px;
  }

  .panel-close {
    background: none;
    border: none;
    cursor: pointer;
    font-size: 18px;
    color: var(--text-muted);
    padding: 0;
    line-height: 1;
  }
  .panel-close:hover { color: var(--text); }

  .section-toggle-row { padding: 7px 16px; }

  .toggle-label {
    display: flex;
    align-items: center;
    gap: 10px;
    cursor: pointer;
    font-size: 14px;
    color: var(--text);
  }
  .toggle-label input[type="checkbox"] { display: none; }

  .toggle-track {
    width: 30px;
    height: 17px;
    border-radius: 9px;
    background: var(--border);
    position: relative;
    transition: background 0.15s;
    flex-shrink: 0;
  }
  .toggle-label input:checked + .toggle-track { background: var(--forest); }

  .toggle-thumb {
    position: absolute;
    top: 2px; left: 2px;
    width: 13px; height: 13px;
    border-radius: 50%;
    background: #fff;
    transition: transform 0.15s;
    box-shadow: 0 1px 2px rgba(0,0,0,0.18);
  }
  .toggle-label input:checked + .toggle-track .toggle-thumb { transform: translateX(13px); }

  .panel-backdrop {
    position: fixed;
    inset: 0;
    z-index: 99;
  }

  /* ── Hero Zone ───────────────────────────────────────────── */
  .hero-zone {
    margin-bottom: 48px;
  }

  .hero-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 10px;
  }

  .hero-label {
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.07em;
    color: var(--text-muted);
  }

  .hero-number-row {
    display: flex;
    align-items: baseline;
    gap: 18px;
    margin-bottom: 32px;
  }

  .hero-number {
    font-family: var(--font-serif);
    font-size: 56px;
    font-weight: 700;
    color: var(--text);
    line-height: 1;
    letter-spacing: -0.03em;
  }

  .hero-trend {
    font-size: 14px;
    color: var(--moss);
    font-weight: 500;
  }

  /* ── Period Tabs ─────────────────────────────────────────── */
  .period-tabs { display: flex; }

  .period-tab {
    background: none;
    border: none;
    padding: 4px 12px;
    font-size: 13px;
    font-family: inherit;
    color: var(--text-muted);
    cursor: pointer;
    border-radius: 4px;
  }
  .period-tab:hover { color: var(--text); }
  .period-tab.active { color: var(--forest); font-weight: 600; }

  /* ── Chart ───────────────────────────────────────────────── */
  .chart-wrap {
    height: 240px;
    position: relative;
  }

  .chart-empty {
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
    color: var(--text-light);
  }

  /* ── Split Layout ────────────────────────────────────────── */
  .dash-split {
    display: grid;
    grid-template-columns: 3fr 2fr;
    border-top: 1px solid var(--border-light);
    padding-top: 32px;
    margin-bottom: 48px;
  }

  .split-left {
    padding-right: 48px;
  }

  .split-right {
    padding-left: 48px;
    border-left: 1px solid var(--border-light);
  }

  .split-header {
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

  .view-all-link {
    background: none;
    border: none;
    font-size: 13px;
    color: var(--forest);
    cursor: pointer;
    font-family: inherit;
    padding: 0;
  }
  .view-all-link:hover { opacity: 0.75; }

  /* ── Content Table ───────────────────────────────────────── */
  .content-table-head {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 80px 90px;
    gap: 8px;
    padding-bottom: 10px;
    border-bottom: 1px solid var(--border-light);
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.07em;
    color: var(--text-muted);
  }

  .content-table-row {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 80px 90px;
    gap: 8px;
    align-items: center;
    padding: 12px 0;
    border-bottom: 1px solid var(--border-light);
    font-size: 14px;
  }
  .content-table-row:last-child { border-bottom: none; }

  .content-item-title {
    color: var(--text);
    font-weight: 500;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .content-item-date {
    font-size: 12px;
    color: var(--text-muted);
    white-space: nowrap;
  }

  /* ── Status Badges ───────────────────────────────────────── */
  .badge {
    font-size: 11px;
    font-weight: 500;
    padding: 2px 8px;
    border-radius: 99px;
    display: inline-block;
    text-transform: capitalize;
  }
  .badge-published { background: var(--moss-light); color: var(--moss); }
  .badge-draft     { background: var(--bg-hover);   color: var(--text-muted); }
  .badge-scheduled { background: var(--forest-light); color: var(--forest); }

  /* ── Stat List (right column) ────────────────────────────── */
  .stat-list-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 0;
    border-bottom: 1px solid var(--border-light);
    font-size: 14px;
  }
  .stat-list-row:last-child { border-bottom: none; }

  .stat-list-label { color: var(--text-secondary); }

  .stat-list-value { color: var(--text); font-weight: 500; }
  .stat-list-value.positive { color: var(--moss); }

  /* ── Activity Section ────────────────────────────────────── */
  .activity-section {
    border-top: 1px solid var(--border-light);
    padding-top: 32px;
    margin-bottom: 32px;
  }

  .activity-row {
    display: flex;
    align-items: center;
    gap: 12px;
    height: 44px;
    border-bottom: 1px solid var(--border-light);
    font-size: 14px;
  }
  .activity-row:last-child { border-bottom: none; }

  .activity-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    flex-shrink: 0;
    opacity: 0.85;
  }

  .activity-desc {
    flex: 1;
    color: var(--text-secondary);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .activity-time {
    font-size: 12px;
    color: var(--text-light);
    white-space: nowrap;
    flex-shrink: 0;
  }

  .empty-inline {
    padding: 32px 0;
    font-size: 14px;
    color: var(--text-light);
    text-align: center;
  }

  /* ── Skeleton Loading ────────────────────────────────────── */
  .skel {
    background: var(--bg-hover);
    border-radius: 4px;
    animation: pulse 1.5s ease-in-out infinite;
  }
  .skel-hero-label  { width: 90px;  height: 9px;  margin-bottom: 14px; }
  .skel-hero-number { width: 140px; height: 60px; margin-bottom: 32px; border-radius: 6px; }
  .skel-chart       { height: 240px; border-radius: var(--radius-sm); }

  @keyframes pulse {
    0%, 100% { opacity: 1; }
    50%       { opacity: 0.4; }
  }

  /* ── Responsive ──────────────────────────────────────────── */
  @media (max-width: 800px) {
    .dash-header { flex-direction: column; gap: 16px; }
    .dash-split { grid-template-columns: 1fr; }
    .split-left  { padding-right: 0; }
    .split-right {
      border-left: none;
      border-top: 1px solid var(--border-light);
      padding-left: 0;
      padding-top: 32px;
      margin-top: 32px;
    }
    .dash-actions { flex-wrap: wrap; }
  }
</style>
