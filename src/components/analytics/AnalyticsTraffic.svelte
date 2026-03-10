<script>
  import { onMount } from 'svelte';
  import { analytics as analyticsApi } from '$lib/api.js';
  import { navigate, addToast } from '$lib/stores.js';

  // ── State ──────────────────────────────────────────────────
  let period = $state('30days');
  let traffic = $state(null);
  let seo = $state(null);
  let content = $state(null);
  let members = $state(null);
  let geo = $state(null);
  let loading = $state(true);
  let trafficChartCanvas = $state(null);
  let trafficChartInstance = null;
  let showAllReferrers = $state(false);
  let seoPassingOpen = $state(false);
  let topPagesSort = $state('pageviews');
  let topPagesPage = $state(0);
  let activeTrafficLines = $state({ pageviews: true, unique: true });
  const PAGE_SIZE = 10;

  const PERIODS = [
    { val: '7days',    label: 'Last 7 days' },
    { val: '30days',   label: 'Last 30 days' },
    { val: '90days',   label: 'Last 90 days' },
    { val: '12months', label: 'Last 12 months' },
  ];

  // ── Derived ────────────────────────────────────────────────
  let periodLabel = $derived(PERIODS.find(p => p.val === period)?.label ?? 'Last 30 days');

  let sortedTopPages = $derived.by(() => {
    if (!traffic?.top_pages) return [];
    return [...traffic.top_pages].sort((a, b) => {
      if (topPagesSort === 'pageviews')  return b.pageviews - a.pageviews;
      if (topPagesSort === 'unique')     return b.unique_visitors - a.unique_visitors;
      if (topPagesSort === 'duration')   return b.avg_duration - a.avg_duration;
      return 0;
    });
  });

  let pagedTopPages = $derived(sortedTopPages.slice(topPagesPage * PAGE_SIZE, (topPagesPage + 1) * PAGE_SIZE));
  let totalPages = $derived(Math.ceil((traffic?.top_pages?.length ?? 0) / PAGE_SIZE));

  // ── Helpers ────────────────────────────────────────────────
  function fmtNum(n) {
    if (n == null) return '—';
    if (n >= 1000000) return (n / 1000000).toFixed(1) + 'M';
    if (n >= 1000) return (n / 1000).toFixed(1) + 'K';
    return String(n);
  }

  function fmtDur(secs) {
    if (!secs) return '—';
    const m = Math.floor(secs / 60);
    const s = secs % 60;
    return `${m}:${String(s).padStart(2, '0')}`;
  }

  function fmtPct(rate) {
    if (rate == null) return '—';
    return Math.round(rate * 100) + '%';
  }

  function trendBadge(change) {
    if (change == null) return '';
    if (change > 0) return `↑ +${change}%`;
    if (change < 0) return `↓ ${change}%`;
    return '→ same';
  }

  function trendClass(change) {
    if (change == null) return '';
    if (change > 0) return 'trend-up';
    if (change < 0) return 'trend-down';
    return 'trend-flat';
  }

  function seoScoreClass(score) {
    if (score >= 80) return 'score-good';
    if (score >= 60) return 'score-warn';
    return 'score-bad';
  }

  function bounceClass(rate) {
    if (rate < 0.30) return 'bounce-good';
    if (rate < 0.60) return 'bounce-warn';
    return 'bounce-bad';
  }

  function getSiteUrl() {
    const base = window.location.origin + window.location.pathname;
    return base.replace(/\/outpost\/.*$/, '');
  }

  function countryFlag(code) {
    if (!code || code.length !== 2) return '';
    const c = code.toUpperCase();
    return String.fromCodePoint(...[...c].map(ch => ch.charCodeAt(0) + 0x1F1A5));
  }

  const COUNTRY_NAMES = {
    US:'United States',GB:'United Kingdom',DE:'Germany',FR:'France',CA:'Canada',
    AU:'Australia',JP:'Japan',BR:'Brazil',IN:'India',NL:'Netherlands',
    IT:'Italy',ES:'Spain',MX:'Mexico',KR:'South Korea',SE:'Sweden',
    CH:'Switzerland',NO:'Norway',DK:'Denmark',FI:'Finland',PL:'Poland',
    BE:'Belgium',AT:'Austria',PT:'Portugal',IE:'Ireland',NZ:'New Zealand',
    SG:'Singapore',HK:'Hong Kong',TW:'Taiwan',IL:'Israel',ZA:'South Africa',
    AR:'Argentina',CL:'Chile',CO:'Colombia',PH:'Philippines',TH:'Thailand',
    MY:'Malaysia',ID:'Indonesia',VN:'Vietnam',TR:'Turkey',RU:'Russia',
    UA:'Ukraine',RO:'Romania',CZ:'Czechia',HU:'Hungary',GR:'Greece',
    CN:'China',EG:'Egypt',NG:'Nigeria',KE:'Kenya',AE:'UAE',SA:'Saudi Arabia',
  };

  function countryName(code) {
    return COUNTRY_NAMES[code?.toUpperCase()] || code?.toUpperCase() || '—';
  }

  function faviconUrl(domain) {
    if (!domain || domain === 'direct') return null;
    return `https://www.google.com/s2/favicons?domain=${encodeURIComponent(domain)}&sz=16`;
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

  async function buildTrafficChart() {
    if (!trafficChartCanvas || !traffic?.chart) return;
    const data = traffic.chart;
    if (data.length < 2) return;

    try {
      const ChartJs = await loadChartJs();
      if (trafficChartInstance) { trafficChartInstance.destroy(); trafficChartInstance = null; }

      const style   = getComputedStyle(document.documentElement);
      const textMuted = style.getPropertyValue('--text-muted').trim() || '#8A857D';
      const forest  = '#2D5A47';
      const sage    = '#6B8F71';
      const isDark  = document.documentElement.classList.contains('dark');
      const ctx     = trafficChartCanvas.getContext('2d');

      const labels = data.map(d => {
        const dt = new Date(d.date + 'T00:00:00');
        return dt.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
      });

      const gradientPV = ctx.createLinearGradient(0, 0, 0, trafficChartCanvas.height || 240);
      gradientPV.addColorStop(0, 'rgba(45, 90, 71, 0.10)');
      gradientPV.addColorStop(1, 'rgba(45, 90, 71, 0.00)');

      const datasets = [];
      if (activeTrafficLines.pageviews) {
        datasets.push({
          label: 'Pageviews',
          data: data.map(d => d.pageviews),
          borderColor: forest,
          backgroundColor: gradientPV,
          fill: true,
          tension: 0.4,
          pointRadius: 0,
          pointHoverRadius: 4,
          pointHoverBackgroundColor: forest,
          pointHoverBorderColor: '#fff',
          pointHoverBorderWidth: 2,
          borderWidth: 2,
        });
      }
      if (activeTrafficLines.unique) {
        datasets.push({
          label: 'Unique Visitors',
          data: data.map(d => d.unique_visitors),
          borderColor: sage,
          backgroundColor: 'transparent',
          fill: false,
          tension: 0.4,
          pointRadius: 0,
          pointHoverRadius: 4,
          pointHoverBackgroundColor: sage,
          pointHoverBorderColor: '#fff',
          pointHoverBorderWidth: 2,
          borderWidth: 2,
        });
      }

      trafficChartInstance = new ChartJs(ctx, {
        type: 'line',
        data: { labels, datasets },
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
    const canvas = trafficChartCanvas;
    const chartData = traffic?.chart;
    if (!canvas || !chartData) return;
    buildTrafficChart();
    return () => {
      if (trafficChartInstance) { trafficChartInstance.destroy(); trafficChartInstance = null; }
    };
  });

  // Rebuild when active lines toggle
  $effect(() => {
    const _ = activeTrafficLines.pageviews || activeTrafficLines.unique;
    if (!trafficChartCanvas || !traffic?.chart) return;
    buildTrafficChart();
  });

  // ── Data loading ───────────────────────────────────────────
  async function loadAll(p = '30days') {
    loading = true;
    try {
      const [trafficRes, seoRes, contentRes, membersRes, geoRes] = await Promise.allSettled([
        analyticsApi.traffic(p),
        analyticsApi.seo(),
        analyticsApi.content(p),
        analyticsApi.members(p),
        analyticsApi.geo(p),
      ]);
      traffic = trafficRes.status === 'fulfilled' ? trafficRes.value.data : null;
      seo     = seoRes.status     === 'fulfilled' ? seoRes.value.data     : null;
      content = contentRes.status === 'fulfilled' ? contentRes.value.data : null;
      members = membersRes.status === 'fulfilled' ? membersRes.value.data : null;
      geo     = geoRes.status     === 'fulfilled' ? geoRes.value.data     : null;
    } catch (err) {
      addToast('Failed to load analytics', 'error');
    } finally {
      loading = false;
    }
  }

  async function changePeriod(p) {
    period = p;
    topPagesPage = 0;
    await loadAll(p);
  }

  onMount(() => loadAll(period));
</script>

<div class="analytics-traffic">

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

  {:else}

    <!-- ── Section 1: Traffic Overview ──────────────────── -->
    <div class="hero-zone">
      <div class="hero-stats-row">
        <div class="hero-stat">
          <div class="stat-label">Total Pageviews</div>
          <div class="stat-number">{fmtNum(traffic?.totals.pageviews ?? 0)}</div>
          <div class={`stat-trend ${trendClass(traffic?.totals.pv_change)}`}>
            {trendBadge(traffic?.totals.pv_change)}
          </div>
        </div>
        <div class="hero-stat">
          <div class="stat-label">Unique Visitors</div>
          <div class="stat-number">{fmtNum(traffic?.totals.unique_visitors ?? 0)}</div>
          <div class={`stat-trend ${trendClass(traffic?.totals.uv_change)}`}>
            {trendBadge(traffic?.totals.uv_change)}
          </div>
        </div>
        <div class="hero-stat">
          <div class="stat-label">Avg Session Length</div>
          <div class="stat-number">{fmtDur(traffic?.totals.avg_session_secs)}</div>
          <div class="stat-trend trend-flat">vs prior period</div>
        </div>
        <div class="hero-stat">
          <div class="stat-label">Returning Visitors</div>
          <div class="stat-number">{fmtPct(traffic?.totals.returning_rate)}</div>
          <div class="stat-trend trend-flat">of unique visitors</div>
        </div>
      </div>

      <!-- Chart toggle + canvas -->
      {#if (traffic?.totals.pageviews ?? 0) > 0}
        <div class="chart-legend">
          <button
            class="legend-chip"
            class:active={activeTrafficLines.pageviews}
            style="--chip-color: var(--forest)"
            onclick={() => { activeTrafficLines = { ...activeTrafficLines, pageviews: !activeTrafficLines.pageviews }; }}
          >
            <span class="legend-dot"></span> Pageviews
          </button>
          <button
            class="legend-chip"
            class:active={activeTrafficLines.unique}
            style="--chip-color: var(--sage)"
            onclick={() => { activeTrafficLines = { ...activeTrafficLines, unique: !activeTrafficLines.unique }; }}
          >
            <span class="legend-dot" style="background: var(--sage);"></span> Unique Visitors
          </button>
        </div>
        <div class="chart-wrap">
          <canvas bind:this={trafficChartCanvas}></canvas>
        </div>
      {:else}
        <div class="section-empty">
          No traffic data yet — make sure your site is live and receiving visitors.
        </div>
      {/if}
    </div>

    <!-- ── Section 2: Sources & Devices ─────────────────── -->
    {#if (traffic?.totals.pageviews ?? 0) > 0}
      <div class="dash-split">
        <!-- Left: Referrers -->
        <div class="split-left">
          <div class="split-header">
            <span class="split-title">Traffic Sources</span>
          </div>

          {#if traffic?.referrers?.length}
            <div class="ref-table">
              <div class="ref-head">
                <span>Source</span>
                <span>Visits</span>
                <span>Share</span>
                <span></span>
              </div>
              {#each (showAllReferrers ? traffic.referrers : traffic.referrers.slice(0, 5)) as ref}
                <div class="ref-row">
                  <span class="ref-domain">
                    {#if ref.domain !== 'direct' && faviconUrl(ref.domain)}
                      <img
                        src={faviconUrl(ref.domain)}
                        alt=""
                        class="ref-favicon"
                        onerror={(e) => e.target.style.display = 'none'}
                      />
                    {:else}
                      <span class="ref-direct-icon">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
                      </span>
                    {/if}
                    {ref.domain === 'direct' ? 'Direct / None' : ref.domain}
                  </span>
                  <span class="ref-visits">{fmtNum(ref.visits)}</span>
                  <span class="ref-pct">{Math.round(ref.share * 100)}%</span>
                  <span class="ref-bar-wrap">
                    <span class="ref-bar-fill" style="width: {Math.round(ref.share * 100)}%"></span>
                  </span>
                </div>
              {/each}
            </div>
            {#if traffic.referrers.length > 5}
              <button class="show-more-btn" onclick={() => showAllReferrers = !showAllReferrers}>
                {showAllReferrers ? 'Show less' : `Show all ${traffic.referrers.length} sources`} →
              </button>
            {/if}
          {:else}
            <div class="empty-inline">No referrer data yet</div>
          {/if}
        </div>

        <!-- Right: Devices -->
        <div class="split-right">
          <div class="split-header">
            <span class="split-title">Devices</span>
          </div>

          {#if traffic?.devices}
            <div class="device-list">
              {#each [
                { key: 'desktop', label: 'Desktop',  color: 'var(--forest)' },
                { key: 'mobile',  label: 'Mobile',   color: 'var(--sage)'   },
                { key: 'tablet',  label: 'Tablet',   color: 'var(--clay)'   },
              ] as dev}
                {@const pct = Math.round((traffic.devices[dev.key] ?? 0) * 100)}
                <div class="device-row">
                  <span class="device-label">{dev.label}</span>
                  <span class="device-pct">{pct}%</span>
                  <span class="device-bar-wrap">
                    <span class="device-bar-fill" style="width: {pct}%; background: {dev.color};"></span>
                  </span>
                </div>
              {/each}
            </div>
          {/if}
        </div>
      </div>
    {/if}

    <!-- ── Section 3: Top Pages ──────────────────────────── -->
    {#if traffic?.top_pages?.length}
      <div class="analytics-section">
        <div class="section-header">
          <span class="split-title">Top Pages</span>
          <div class="sort-tabs">
            {#each [['pageviews','Views'], ['unique','Unique'], ['duration','Avg Time']] as [val, lbl]}
              <button
                class="sort-tab"
                class:active={topPagesSort === val}
                onclick={() => { topPagesSort = val; topPagesPage = 0; }}
              >{lbl}</button>
            {/each}
          </div>
        </div>

        <div class="pages-table">
          <div class="pages-head">
            <span>Page</span>
            <span class="col-num">Views</span>
            <span class="col-num">Unique</span>
            <span class="col-num">Avg Time</span>
          </div>
          {#each pagedTopPages as pg (pg.path)}
            <div class="pages-row">
              <span class="page-path">
                <a href="{getSiteUrl()}{pg.path}" target="_blank" rel="noopener">{pg.path}</a>
              </span>
              <span class="col-num">{fmtNum(pg.pageviews)}</span>
              <span class="col-num">{fmtNum(pg.unique_visitors)}</span>
              <span class="col-num">{fmtDur(pg.avg_duration)}</span>
            </div>
          {/each}
        </div>

        {#if totalPages > 1}
          <div class="pagination">
            <button class="page-btn" disabled={topPagesPage === 0} onclick={() => topPagesPage--}>← Prev</button>
            <span class="page-info">{topPagesPage + 1} / {totalPages}</span>
            <button class="page-btn" disabled={topPagesPage >= totalPages - 1} onclick={() => topPagesPage++}>Next →</button>
          </div>
        {/if}
      </div>
    {/if}

    <!-- ── Section 4: SEO Health ─────────────────────────── -->
    {#if seo}
      <div class="analytics-section">
        <div class="section-header">
          <span class="split-title">SEO Health</span>
          <div class="seo-score-wrap">
            <span class="seo-score {seoScoreClass(seo.score)}">{seo.score}</span>
            <span class="seo-score-label">/ 100</span>
          </div>
        </div>

        {#if seo.critical.length}
          <div class="seo-group">
            <div class="seo-group-label critical">⚠ {seo.critical.length} Critical {seo.critical.length === 1 ? 'Issue' : 'Issues'}</div>
            {#each seo.critical as issue}
              <div class="seo-row">
                <span class="seo-dot critical"></span>
                <span class="seo-msg">{issue.msg}</span>
                <button class="seo-fix-link" onclick={() => navigate(issue.link)}>
                  → Fix in {issue.link === 'pages' ? 'Pages' : 'Collections'}
                </button>
              </div>
            {/each}
          </div>
        {/if}

        {#if seo.warnings.length}
          <div class="seo-group">
            <div class="seo-group-label warning">⚠ {seo.warnings.length} {seo.warnings.length === 1 ? 'Warning' : 'Warnings'}</div>
            {#each seo.warnings as issue}
              <div class="seo-row">
                <span class="seo-dot warning"></span>
                <span class="seo-msg">{issue.msg}</span>
                <button class="seo-fix-link" onclick={() => navigate(issue.link)}>
                  → Fix in {issue.link === 'pages' ? 'Pages' : 'Collections'}
                </button>
              </div>
            {/each}
          </div>
        {/if}

        {#if seo.passing.length}
          <div class="seo-group">
            <button class="seo-passing-toggle" onclick={() => seoPassingOpen = !seoPassingOpen}>
              <span class="seo-group-label passing">✓ {seo.passing.length} {seo.passing.length === 1 ? 'check' : 'checks'} passing</span>
              <svg class="chevron" class:rotated={seoPassingOpen} width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
            </button>
            {#if seoPassingOpen}
              {#each seo.passing as msg}
                <div class="seo-row">
                  <span class="seo-dot passing"></span>
                  <span class="seo-msg">{msg}</span>
                </div>
              {/each}
            {/if}
          </div>
        {/if}

        {#if !seo.critical.length && !seo.warnings.length}
          <div class="section-empty" style="padding: 24px 0;">
            All SEO checks are passing. Your site looks great!
          </div>
        {/if}
      </div>
    {/if}

    <!-- ── Section 5: Content Performance ───────────────── -->
    {#if content?.has_collections}
      <div class="analytics-section">
        <div class="section-header">
          <span class="split-title">Content Performance</span>
        </div>

        {#if content.gap_message}
          <p class="gap-message">{content.gap_message}</p>
        {/if}

        {#if content.top_content?.length}
          {#if content.top_content.every(i => i.views === 0)}
            <p class="no-views-hint">Views appear once visitors browse your posts.</p>
          {/if}
          <div class="pages-table" style="margin-top: 20px;">
            <div class="pages-head content-head">
              <span>Title</span>
              <span>Collection</span>
              <span class="col-num">Views</span>
              <span class="col-num">Avg Time</span>
              <span class="col-right">Published</span>
            </div>
            {#each content.top_content as item (item.id)}
              <div class="pages-row content-row">
                <span class="page-path">
                  <a href="{getSiteUrl()}{item.path}" target="_blank" rel="noopener">{item.title}</a>
                </span>
                <span class="content-coll">{item.collection}</span>
                <span class="col-num">{fmtNum(item.views)}</span>
                <span class="col-num">{fmtDur(item.avg_duration)}</span>
                <span class="col-right content-date">
                  {item.published_at ? new Date(item.published_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric' }) : '—'}
                </span>
              </div>
            {/each}
          </div>
        {:else}
          <div class="empty-inline">No published content yet</div>
        {/if}
      </div>
    {/if}

    <!-- ── Section 6: Geo ──────────────────────────────── -->
    {#if geo?.has_data && geo.countries?.length}
      <div class="analytics-section">
        <div class="section-header">
          <span class="split-title">Visitors by Country</span>
        </div>
        <div class="geo-list">
          {#each geo.countries.slice(0, 10) as country}
            <div class="geo-row">
              <span class="geo-flag">{countryFlag(country.country_code)}</span>
              <span class="geo-name">{countryName(country.country_code)}</span>
              <div class="geo-bar-wrap">
                <div class="geo-bar" style="width: {country.percentage}%"></div>
              </div>
              <span class="geo-count">{fmtNum(country.count)}</span>
              <span class="geo-pct">{country.percentage}%</span>
            </div>
          {/each}
        </div>
      </div>
    {/if}

    <!-- ── Section 7: Member Metrics ────────────────────── -->
    {#if members?.has_members}
      <div class="analytics-section">
        <div class="section-header">
          <span class="split-title">Member Metrics</span>
        </div>

        <!-- Key metrics row -->
        <div class="member-metrics-row">
          <div class="member-metric">
            <div class="stat-label">Total Members</div>
            <div class="stat-number">{fmtNum(members.totals.total)}</div>
            {#if members.trends.new_this_period > 0}
              <div class="stat-trend trend-up">↑ +{members.trends.new_this_period} this period</div>
            {/if}
          </div>
          <div class="member-metric">
            <div class="stat-label">Paid Members</div>
            <div class="stat-number">{fmtNum(members.totals.paid)}</div>
            {#if members.trends.new_paid_this_period > 0}
              <div class="stat-trend trend-up">↑ +{members.trends.new_paid_this_period} this period</div>
            {/if}
          </div>
          <div class="member-metric">
            <div class="stat-label">Conversion Rate</div>
            <div class="stat-number">{fmtPct(members.totals.conversion)}</div>
            <div class="stat-trend trend-flat">free → paid</div>
          </div>
          <div class="member-metric">
            <div class="stat-label">Est. MRR</div>
            {#if members.totals.mrr != null}
              <div class="stat-number">${members.totals.mrr.toLocaleString()}</div>
              <div class="stat-trend trend-flat">{members.totals.paid} × ${members.totals.price}/mo</div>
            {:else}
              <div class="stat-number">—</div>
              <div class="stat-trend trend-flat">
                <button class="inline-link" onclick={() => navigate('settings')}>Set price in Settings</button>
              </div>
            {/if}
          </div>
        </div>

        <!-- Funnel -->
        <div class="funnel">
          <div class="funnel-step">
            <div class="funnel-num">{fmtNum(members.visitors || 0)}</div>
            <div class="funnel-label">Visitors</div>
          </div>
          <div class="funnel-arrow">→</div>
          <div class="funnel-step">
            <div class="funnel-num">{fmtNum(members.totals.total)}</div>
            <div class="funnel-label">
              Free Members
              {#if (members.visitors ?? 0) > 0}
                <span class="funnel-pct">{fmtPct(members.totals.total / members.visitors)}</span>
              {/if}
            </div>
          </div>
          <div class="funnel-arrow">→</div>
          <div class="funnel-step funnel-step-paid">
            <div class="funnel-num">{fmtNum(members.totals.paid)}</div>
            <div class="funnel-label">
              Paid Members
              <span class="funnel-pct">{fmtPct(members.totals.conversion)}</span>
            </div>
          </div>
        </div>

        <!-- Activity -->
        <div class="member-activity">
          <div class="split-title" style="margin-bottom: 14px;">Member Activity</div>
          <div class="stat-list">
            <div class="stat-list-row">
              <span class="stat-list-label">New members this period</span>
              <span class="stat-list-value">{members.trends.new_this_period}</span>
            </div>
            <div class="stat-list-row">
              <span class="stat-list-label">Free members</span>
              <span class="stat-list-value">{members.totals.free}</span>
            </div>
            <div class="stat-list-row">
              <span class="stat-list-label">Paid members</span>
              <span class="stat-list-value">{members.totals.paid}</span>
            </div>
            {#if members.top_referrer}
              <div class="stat-list-row">
                <span class="stat-list-label">Most signups from</span>
                <span class="stat-list-value">{members.top_referrer}</span>
              </div>
            {/if}
          </div>
        </div>
      </div>

    {:else if members && !members.has_members}
      <!-- Soft callout when members module isn't in use -->
      <div class="analytics-section">
        <div class="members-callout">
          <div class="callout-text">
            Enable members on your site to see subscription analytics.
          </div>
          <button class="btn-ghost-sm" onclick={() => navigate('settings')}>
            Go to Settings →
          </button>
        </div>
      </div>
    {/if}

  {/if}
</div>

<style>
  /* ── Layout ──────────────────────────────────────────────── */
  .analytics-traffic {
    /* inherits max-width from parent shell */
  }

  /* ── Period Selector (moved to top of traffic tab) ────── */
  .analytics-traffic > .period-selector {
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

  /* ── Hero Zone (Traffic Stats + Chart) ───────────────────── */
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
  .chart-legend {
    display: flex;
    gap: 16px;
    margin-bottom: 14px;
  }

  .legend-chip {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: none;
    border: 1px solid var(--border-light);
    border-radius: 99px;
    padding: 4px 10px;
    font-size: 12px;
    font-family: inherit;
    color: var(--text-muted);
    cursor: pointer;
    opacity: 0.5;
    transition: opacity 0.15s;
  }
  .legend-chip.active { opacity: 1; color: var(--text); }
  .legend-chip:hover  { opacity: 0.8; }

  .legend-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: var(--chip-color, var(--forest));
    flex-shrink: 0;
  }

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

  /* ── Split Layout ────────────────────────────────────────── */
  .dash-split {
    display: grid;
    grid-template-columns: 3fr 2fr;
    border-top: 1px solid var(--border-light);
    padding-top: 32px;
    margin-bottom: 48px;
  }

  .split-left  { padding-right: 48px; }
  .split-right { padding-left: 48px; border-left: 1px solid var(--border-light); }

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

  /* ── Referrers ───────────────────────────────────────────── */
  .ref-head {
    display: grid;
    grid-template-columns: 1fr 60px 44px 80px;
    gap: 8px;
    padding-bottom: 8px;
    border-bottom: 1px solid var(--border-light);
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.07em;
    color: var(--text-muted);
  }

  .ref-row {
    display: grid;
    grid-template-columns: 1fr 60px 44px 80px;
    gap: 8px;
    align-items: center;
    padding: 9px 0;
    border-bottom: 1px solid var(--border-light);
    font-size: 13px;
  }
  .ref-row:last-child { border-bottom: none; }

  .ref-domain {
    display: flex;
    align-items: center;
    gap: 7px;
    color: var(--text);
    font-weight: 500;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .ref-favicon {
    width: 14px;
    height: 14px;
    flex-shrink: 0;
    border-radius: 2px;
  }

  .ref-direct-icon {
    width: 14px;
    height: 14px;
    color: var(--text-muted);
    flex-shrink: 0;
    display: flex;
    align-items: center;
  }

  .ref-visits { color: var(--text); font-weight: 500; }
  .ref-pct    { color: var(--text-muted); font-size: 12px; }

  .ref-bar-wrap {
    height: 5px;
    background: var(--bg-hover);
    border-radius: 99px;
    overflow: hidden;
  }
  .ref-bar-fill {
    height: 100%;
    background: var(--forest);
    border-radius: 99px;
    transition: width 0.4s ease;
  }

  .show-more-btn {
    margin-top: 12px;
    background: none;
    border: none;
    font-size: 13px;
    color: var(--forest);
    cursor: pointer;
    font-family: inherit;
    padding: 0;
  }
  .show-more-btn:hover { opacity: 0.75; }

  /* ── Devices ─────────────────────────────────────────────── */
  .device-row {
    display: grid;
    grid-template-columns: 80px 40px 1fr;
    gap: 12px;
    align-items: center;
    padding: 10px 0;
    border-bottom: 1px solid var(--border-light);
    font-size: 13px;
  }
  .device-row:last-child { border-bottom: none; }

  .device-label { color: var(--text-secondary); }
  .device-pct   { color: var(--text); font-weight: 500; text-align: right; }

  .device-bar-wrap {
    height: 5px;
    background: var(--bg-hover);
    border-radius: 99px;
    overflow: hidden;
  }
  .device-bar-fill {
    height: 100%;
    border-radius: 99px;
    transition: width 0.4s ease;
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

  /* ── Sort Tabs ───────────────────────────────────────────── */
  .sort-tabs { display: flex; gap: 0; }

  .sort-tab {
    background: none;
    border: none;
    padding: 4px 10px;
    font-size: 12px;
    font-family: inherit;
    color: var(--text-muted);
    cursor: pointer;
    border-radius: 4px;
  }
  .sort-tab:hover { color: var(--text); }
  .sort-tab.active { color: var(--forest); font-weight: 600; }

  /* ── Pages Table ─────────────────────────────────────────── */
  .pages-head {
    display: grid;
    grid-template-columns: 1fr 70px 70px 70px;
    gap: 8px;
    padding-bottom: 10px;
    border-bottom: 1px solid var(--border-light);
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.07em;
    color: var(--text-muted);
  }

  .pages-row {
    display: grid;
    grid-template-columns: 1fr 70px 70px 70px;
    gap: 8px;
    align-items: center;
    padding: 11px 0;
    border-bottom: 1px solid var(--border-light);
    font-size: 14px;
  }
  .pages-row:last-child { border-bottom: none; }

  .page-path {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
  .page-path a {
    color: var(--text);
    text-decoration: none;
    font-weight: 500;
  }
  .page-path a:hover { color: var(--forest); }

  .col-num  { color: var(--text-secondary); text-align: right; }
  .col-right { color: var(--text-secondary); text-align: right; }

  /* ── Content Table ───────────────────────────────────────── */
  .content-head {
    grid-template-columns: 1fr 100px 60px 70px 80px !important;
  }
  .content-row {
    grid-template-columns: 1fr 100px 60px 70px 80px !important;
  }

  .content-coll {
    font-size: 12px;
    color: var(--text-muted);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .content-date { font-size: 12px; }

  .gap-message {
    font-size: 14px;
    color: var(--text-muted);
    margin: 0 0 20px;
  }

  .no-views-hint {
    font-size: 13px;
    color: var(--text-muted);
    margin: 16px 0 0;
  }

  /* ── Pagination ──────────────────────────────────────────── */
  .pagination {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-top: 16px;
    padding-top: 16px;
    border-top: 1px solid var(--border-light);
  }

  .page-btn {
    background: none;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    padding: 5px 12px;
    font-size: 13px;
    font-family: inherit;
    color: var(--text-secondary);
    cursor: pointer;
  }
  .page-btn:hover:not(:disabled) { background: var(--bg-hover); }
  .page-btn:disabled { opacity: 0.4; cursor: default; }

  .page-info {
    font-size: 13px;
    color: var(--text-muted);
    margin: 0 auto;
  }

  /* ── SEO Health ──────────────────────────────────────────── */
  .seo-score-wrap {
    display: flex;
    align-items: baseline;
    gap: 4px;
  }

  .seo-score {
    font-family: var(--font-serif);
    font-size: 32px;
    font-weight: 700;
    line-height: 1;
    letter-spacing: -0.02em;
  }
  .score-good { color: var(--moss); }
  .score-warn { color: var(--amber); }
  .score-bad  { color: var(--rust, #C45C3A); }

  .seo-score-label { font-size: 14px; color: var(--text-muted); }

  .seo-group { margin-bottom: 20px; }

  .seo-group-label {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    margin-bottom: 10px;
    display: inline-block;
  }
  .seo-group-label.critical { color: var(--rust, #C45C3A); }
  .seo-group-label.warning  { color: var(--amber); }
  .seo-group-label.passing  { color: var(--moss); }

  .seo-passing-toggle {
    background: none;
    border: none;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 0;
    font-family: inherit;
  }

  .chevron { transition: transform 0.15s; }
  .chevron.rotated { transform: rotate(180deg); }

  .seo-row {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px 0;
    border-bottom: 1px solid var(--border-light);
    font-size: 14px;
  }
  .seo-row:last-child { border-bottom: none; }

  .seo-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    flex-shrink: 0;
  }
  .seo-dot.critical { background: var(--rust, #C45C3A); }
  .seo-dot.warning  { background: var(--amber); }
  .seo-dot.passing  { background: var(--moss); }

  .seo-msg { flex: 1; color: var(--text-secondary); }

  .seo-fix-link {
    background: none;
    border: none;
    font-size: 13px;
    color: var(--forest);
    cursor: pointer;
    font-family: inherit;
    padding: 0;
    white-space: nowrap;
    flex-shrink: 0;
  }
  .seo-fix-link:hover { opacity: 0.75; }

  /* ── Members ─────────────────────────────────────────────── */
  .member-metrics-row {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 0;
    border-bottom: 1px solid var(--border-light);
    padding-bottom: 28px;
    margin-bottom: 28px;
  }

  .member-metric {
    padding-right: 24px;
    border-right: 1px solid var(--border-light);
    padding-left: 24px;
  }
  .member-metric:first-child { padding-left: 0; }
  .member-metric:last-child  { border-right: none; }

  .inline-link {
    background: none;
    border: none;
    font-size: 12px;
    color: var(--forest);
    cursor: pointer;
    font-family: inherit;
    padding: 0;
  }

  /* ── Funnel ──────────────────────────────────────────────── */
  .funnel {
    display: flex;
    align-items: center;
    gap: 0;
    margin-bottom: 32px;
    padding: 24px 0;
    border-bottom: 1px solid var(--border-light);
  }

  .funnel-step {
    flex: 1;
    text-align: center;
    padding: 16px;
    background: var(--bg-hover);
    border-radius: var(--radius-sm);
  }

  .funnel-step-paid {
    background: color-mix(in srgb, var(--forest) 8%, transparent);
  }

  .funnel-num {
    font-family: var(--font-serif);
    font-size: 28px;
    font-weight: 700;
    color: var(--text);
    letter-spacing: -0.02em;
    line-height: 1;
    margin-bottom: 6px;
  }

  .funnel-label {
    font-size: 12px;
    color: var(--text-muted);
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 4px;
  }

  .funnel-pct {
    font-size: 11px;
    color: var(--text-light);
    background: var(--bg);
    padding: 1px 6px;
    border-radius: 99px;
    margin-top: 2px;
  }

  .funnel-arrow {
    font-size: 20px;
    color: var(--text-light);
    padding: 0 12px;
    flex-shrink: 0;
  }

  .member-activity { margin-top: 0; }

  /* ── Stat list (shared with Dashboard) ───────────────────── */
  .stat-list-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 11px 0;
    border-bottom: 1px solid var(--border-light);
    font-size: 14px;
  }
  .stat-list-row:last-child { border-bottom: none; }

  .stat-list-label { color: var(--text-secondary); }
  .stat-list-value { color: var(--text); font-weight: 500; }

  /* ── Members Callout ─────────────────────────────────────── */
  .members-callout {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 20px 24px;
    background: var(--bg-hover);
    border-radius: var(--radius-sm);
  }

  .callout-text {
    font-size: 14px;
    color: var(--text-secondary);
  }

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
    white-space: nowrap;
  }
  .btn-ghost-sm:hover { background: var(--bg); }

  /* ── Empty inline ────────────────────────────────────────── */
  .empty-inline {
    padding: 32px 0;
    font-size: 14px;
    color: var(--text-light);
    text-align: center;
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

  /* ── Geo ────────────────────────────────────────────────── */
  .geo-list {
    display: flex;
    flex-direction: column;
  }

  .geo-row {
    display: grid;
    grid-template-columns: 28px 140px 1fr 60px 50px;
    gap: 8px;
    align-items: center;
    padding: 8px 0;
    border-bottom: 1px solid var(--border-light);
    font-size: 13px;
  }
  .geo-row:last-child { border-bottom: none; }

  .geo-flag {
    font-size: 16px;
    text-align: center;
  }

  .geo-name {
    font-weight: 500;
    color: var(--text);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .geo-bar-wrap {
    height: 6px;
    background: var(--bg-hover);
    border-radius: 3px;
    overflow: hidden;
  }

  .geo-bar {
    height: 100%;
    background: var(--forest);
    border-radius: 3px;
    min-width: 2px;
  }

  .geo-count {
    text-align: right;
    color: var(--text-secondary);
    font-weight: 500;
  }

  .geo-pct {
    text-align: right;
    color: var(--text-muted);
    font-size: 12px;
  }

  /* ── Responsive ──────────────────────────────────────────── */
  @media (max-width: 900px) {
    .hero-stats-row, .member-metrics-row { grid-template-columns: repeat(2, 1fr); }
    .dash-split { grid-template-columns: 1fr; }
    .split-left  { padding-right: 0; }
    .split-right { border-left: none; border-top: 1px solid var(--border-light); padding-left: 0; padding-top: 32px; margin-top: 32px; }
    .period-selector { flex-wrap: wrap; }
    .content-head, .content-row { grid-template-columns: 1fr 60px 60px 70px !important; }
    .content-row .content-date { display: none; }
  }

  @media (max-width: 600px) {
    .funnel {
      flex-wrap: wrap;
    }

    .stat-number {
      font-size: 36px;
    }

    .ref-head, .ref-row {
      grid-template-columns: 1fr 50px 50px;
    }

    .pages-head, .pages-row {
      grid-template-columns: 1fr 60px 60px;
    }
  }
</style>
