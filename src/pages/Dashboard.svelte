<script>
  import { onMount } from 'svelte';
  import { dashboard as dashboardApi, stats as statsApi } from '$lib/api.js';
  import { user, navigate, addToast } from '$lib/stores.js';
  import { timeAgo } from '$lib/utils.js';

  let currentUser = $derived($user);

  // Layout: full width, no right sidebar
  $effect(() => {
    const layout = document.querySelector('.app-layout');
    if (layout) layout.classList.add('no-right-sidebar');
    return () => {
      if (layout) layout.classList.remove('no-right-sidebar');
    };
  });

  // ── State ──
  let loading = $state(true);
  let stats = $state(null);
  let activity = $state([]);
  let chartPeriod = $state('30days');
  let activeTab = $state('pages');

  let activityCanvas = $state(null);
  let collectionsCanvas = $state(null);
  let statusCanvas = $state(null);
  let activityChart = null;
  let collectionsChart = null;
  let statusChart = null;

  // ── Greeting ──
  let greeting = $derived(() => {
    const h = new Date().getHours();
    if (h < 12) return 'Good morning';
    if (h < 17) return 'Good afternoon';
    return 'Good evening';
  });

  let displayName = $derived(currentUser?.display_name || currentUser?.username || 'there');

  // ── Derived stats ──
  let totalPages = $derived(stats?.totals?.pages ?? 0);
  let totalItems = $derived(stats?.totals?.collection_items ?? 0);
  let totalMedia = $derived(stats?.totals?.media ?? 0);
  let totalCollections = $derived(stats?.totals?.collections ?? 0);
  let memberTotal = $derived(stats?.totals?.members_total ?? 0);
  let pagesTrend = $derived(stats?.trends?.items_this_month ?? null);
  let mediaTrend = $derived(stats?.trends?.media_this_month ?? null);

  let hasMemberData = $derived(memberTotal > 0);

  // ── Chart.js loader ──
  async function loadChartJs() {
    if (window.Chart) return window.Chart;
    return new Promise((resolve, reject) => {
      const script = document.createElement('script');
      script.src = 'https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js';
      script.onload = () => resolve(window.Chart);
      script.onerror = reject;
      document.head.appendChild(script);
    });
  }

  function cssVar(name) {
    return getComputedStyle(document.documentElement).getPropertyValue(name).trim();
  }

  // ── Recent items table data (from activity feed) ──
  let recentPages = $derived((activity || []).filter(a => a.type === 'content').slice(0, 7));
  let recentMedia = $derived((activity || []).filter(a => a.type === 'media').slice(0, 7));
  let recentMembers = $derived((activity || []).filter(a => a.type === 'member').slice(0, 7));

  // ── Build charts ──
  async function buildCharts() {
    try {
      const ChartJs = await loadChartJs();

      // Activity over time chart
      if (activityCanvas) {
        const growth = stats?.growth || stats?.content_activity || [];
        const hasGrowth = growth.length >= 2;

        const ctx = activityCanvas.getContext('2d');
        const gradient = ctx.createLinearGradient(0, 0, 0, 240);
        gradient.addColorStop(0, 'rgba(124, 58, 237, 0.18)');
        gradient.addColorStop(1, 'rgba(124, 58, 237, 0)');

        const labels = hasGrowth
          ? growth.map(g => g.label || g.date || '')
          : ['Day 1', 'Day 5', 'Day 10', 'Day 15', 'Day 20', 'Day 25', 'Day 30'];
        const data = hasGrowth
          ? growth.map(g => g.total ?? g.count ?? 0)
          : [0, 0, 0, 0, 0, 0, 0];

        activityChart = new ChartJs(ctx, {
          type: 'line',
          data: {
            labels,
            datasets: [{
              label: 'Activity',
              data,
              borderColor: '#7C3AED',
              backgroundColor: gradient,
              fill: true,
              tension: 0.4,
              pointRadius: 0,
              pointHoverRadius: 5,
              pointHoverBackgroundColor: '#7C3AED',
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
                backgroundColor: '#1a1a2e',
                titleColor: '#fff',
                bodyColor: '#fff',
                borderWidth: 0,
                cornerRadius: 8,
                padding: 10,
              },
            },
            scales: {
              x: {
                grid: { display: false },
                ticks: { color: cssVar('--dim'), font: { family: 'Inter', size: 11 }, maxTicksLimit: 8 },
                border: { display: false },
              },
              y: {
                grid: { color: 'rgba(255,255,255,0.04)', drawTicks: false },
                ticks: { display: false },
                border: { display: false },
                beginAtZero: true,
              },
            },
            interaction: { mode: 'index', intersect: false },
          },
        });
      }

      // Top collections bar chart
      if (collectionsCanvas) {
        const colls = (stats?.collections || []).slice(0, 5);
        const labels = colls.length ? colls.map(c => c.name || c.slug) : ['No data'];
        const data = colls.length ? colls.map(c => c.item_count || c.published_count || 0) : [0];
        const palette = ['#7C3AED', '#EC4899', '#3B82F6', '#F59E0B', '#34D399'];

        const ctx2 = collectionsCanvas.getContext('2d');
        collectionsChart = new ChartJs(ctx2, {
          type: 'bar',
          data: {
            labels,
            datasets: [{
              data,
              backgroundColor: palette,
              borderRadius: 6,
              barThickness: 26,
            }],
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            indexAxis: 'y',
            plugins: { legend: { display: false } },
            scales: {
              x: {
                grid: { color: 'rgba(255,255,255,0.04)', drawTicks: false },
                ticks: { display: false },
                border: { display: false },
              },
              y: {
                grid: { display: false },
                ticks: { color: cssVar('--sec'), font: { family: 'Inter', size: 12 } },
                border: { display: false },
              },
            },
          },
        });
      }

      // Status doughnut chart (published / draft / scheduled / pending)
      if (statusCanvas) {
        const colls = stats?.collections || [];
        const totals = colls.reduce((acc, c) => {
          acc.published += (c.published_count || 0);
          acc.draft     += (c.draft_count || 0);
          acc.scheduled += (c.scheduled_count || 0);
          acc.pending   += (c.pending_count || 0);
          return acc;
        }, { published: 0, draft: 0, scheduled: 0, pending: 0 });

        const sum = totals.published + totals.draft + totals.scheduled + totals.pending;
        const labels = ['Published', 'Draft', 'Scheduled', 'Pending'];
        const data = sum > 0
          ? [totals.published, totals.draft, totals.scheduled, totals.pending]
          : [1]; // placeholder ring

        const ctx3 = statusCanvas.getContext('2d');
        statusChart = new ChartJs(ctx3, {
          type: 'doughnut',
          data: {
            labels: sum > 0 ? labels : ['No data'],
            datasets: [{
              data,
              backgroundColor: sum > 0
                ? ['#7C3AED', '#FBBF24', '#3B82F6', '#F472B6']
                : ['rgba(255,255,255,0.06)'],
              borderWidth: 0,
              spacing: 2,
            }],
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '65%',
            plugins: {
              legend: {
                position: 'right',
                labels: {
                  color: cssVar('--sec'),
                  font: { family: 'Inter', size: 12 },
                  padding: 12,
                  usePointStyle: true,
                  pointStyleWidth: 8,
                },
              },
            },
          },
        });
      }
    } catch (err) {
      console.error('Chart.js failed:', err);
    }
  }

  // ── Load data ──
  async function loadData() {
    try {
      // Try the dashboard stats API first (richer); fall back to plain stats.
      let dashRes = null;
      try {
        dashRes = await dashboardApi.stats(chartPeriod);
      } catch (e) { /* fallback */ }

      const baseStats = await statsApi.get();

      // Merge: dashboard endpoint provides totals/trends/growth, base provides collection counts
      stats = {
        ...(baseStats || {}),
        ...(dashRes || {}),
        collections: baseStats?.collections || dashRes?.collections || [],
      };

      try {
        const act = await dashboardApi.activity();
        activity = Array.isArray(act) ? act : (act?.activity || []);
      } catch (e) { activity = []; }
    } catch (err) {
      addToast(err.message || 'Failed to load dashboard', 'error');
    }
  }

  function badgeClass(status) {
    if (status === 'published') return 'dash-badge-sm dash-badge-pub';
    if (status === 'draft') return 'dash-badge-sm dash-badge-draft';
    return 'dash-badge-sm dash-badge-no';
  }

  onMount(async () => {
    await loadData();
    loading = false;
    await new Promise(r => setTimeout(r, 50));
    buildCharts();
  });

  $effect(() => {
    return () => {
      if (activityChart) { activityChart.destroy(); activityChart = null; }
      if (collectionsChart) { collectionsChart.destroy(); collectionsChart = null; }
      if (statusChart) { statusChart.destroy(); statusChart = null; }
    };
  });
</script>

<div class="dash-main">
  <!-- Header -->
  <div class="dash-mh">
    <div>
      <h1>{greeting()}, {displayName}</h1>
      <p>Here's what's happening with your site today.</p>
    </div>
  </div>

  <!-- Stats row -->
  <div class="dash-stats">
    <div class="dash-st">
      <div class="dash-st-l">{hasMemberData ? 'Members' : 'Collection Items'}</div>
      <div class="dash-st-v">
        {hasMemberData ? memberTotal : totalItems}
        {#if pagesTrend != null && !hasMemberData}
          <span class="dash-st-c {pagesTrend >= 0 ? 'up' : 'down'}">{pagesTrend >= 0 ? '↑' : '↓'} {Math.abs(pagesTrend)}%</span>
        {/if}
      </div>
    </div>
    <div class="dash-st">
      <div class="dash-st-l">Media Files</div>
      <div class="dash-st-v">
        {totalMedia}
        {#if mediaTrend != null}
          <span class="dash-st-c {mediaTrend >= 0 ? 'up' : 'down'}">{mediaTrend >= 0 ? '↑' : '↓'} {Math.abs(mediaTrend)}%</span>
        {/if}
      </div>
    </div>
    <div class="dash-st">
      <div class="dash-st-l">Collections</div>
      <div class="dash-st-v">{totalCollections}</div>
    </div>
  </div>

  <!-- Activity chart -->
  <div class="dash-cc">
    <div class="dash-cc-h">
      <div class="dash-cc-t">Activity Over Time</div>
      <select class="dash-cc-f" bind:value={chartPeriod} onchange={async () => { await loadData(); if (activityChart) { activityChart.destroy(); activityChart = null; } buildCharts(); }}>
        <option value="7days">Last 7 days</option>
        <option value="30days">Last 30 days</option>
        <option value="90days">Last 90 days</option>
      </select>
    </div>
    <div class="dash-cc-a">
      {#if loading}
        <div class="dash-cc-empty">Loading...</div>
      {:else}
        <canvas bind:this={activityCanvas}></canvas>
      {/if}
    </div>
  </div>

  <!-- Two-column charts -->
  <div class="dash-two">
    <div class="dash-cc">
      <div class="dash-cc-h">
        <div class="dash-cc-t">Top Collections</div>
      </div>
      <div class="dash-cc-a" style="height:220px">
        {#if loading}
          <div class="dash-cc-empty">Loading...</div>
        {:else if !stats?.collections?.length}
          <div class="dash-cc-empty">No collections yet</div>
        {:else}
          <canvas bind:this={collectionsCanvas}></canvas>
        {/if}
      </div>
    </div>
    <div class="dash-cc">
      <div class="dash-cc-h">
        <div class="dash-cc-t">Content Status</div>
      </div>
      <div class="dash-cc-a" style="height:200px">
        {#if loading}
          <div class="dash-cc-empty">Loading...</div>
        {:else}
          <canvas bind:this={statusCanvas}></canvas>
        {/if}
      </div>
    </div>
  </div>

  <!-- Recent activity table -->
  <div class="dash-tbl">
    <div class="dash-tbl-tabs">
      <button class="dash-tbl-tab" class:active={activeTab === 'pages'} onclick={() => activeTab = 'pages'}>Recent Content</button>
      <button class="dash-tbl-tab" class:active={activeTab === 'media'} onclick={() => activeTab = 'media'}>Recent Media</button>
      {#if hasMemberData}
        <button class="dash-tbl-tab" class:active={activeTab === 'members'} onclick={() => activeTab = 'members'}>Recent Members</button>
      {/if}
    </div>

    {#if activeTab === 'pages'}
      {#if recentPages.length === 0}
        <div class="dash-cc-empty" style="padding:32px 0">No content yet — start by creating a collection item.</div>
      {:else}
        <table>
          <thead>
            <tr>
              <th>Title</th>
              <th>Collection</th>
              <th>Status</th>
              <th class="r">Updated</th>
            </tr>
          </thead>
          <tbody>
            {#each recentPages as row}
              <tr>
                <td>{row.title || row.name || row.label || 'Untitled'}</td>
                <td>{row.collection_name || row.collection || '—'}</td>
                <td><span class={badgeClass(row.status)}>{row.status || 'draft'}</span></td>
                <td class="r">{row.updated_at ? timeAgo(row.updated_at) : '—'}</td>
              </tr>
            {/each}
          </tbody>
        </table>
      {/if}
    {/if}

    {#if activeTab === 'media'}
      {#if recentMedia.length === 0}
        <div class="dash-cc-empty" style="padding:32px 0">No media uploads yet.</div>
      {:else}
        <table>
          <thead>
            <tr>
              <th>File</th>
              <th>Type</th>
              <th class="r">Uploaded</th>
            </tr>
          </thead>
          <tbody>
            {#each recentMedia as row}
              <tr>
                <td>{row.title || row.filename || 'File'}</td>
                <td>{row.mime_type || row.type || '—'}</td>
                <td class="r">{row.updated_at ? timeAgo(row.updated_at) : '—'}</td>
              </tr>
            {/each}
          </tbody>
        </table>
      {/if}
    {/if}

    {#if activeTab === 'members' && hasMemberData}
      {#if recentMembers.length === 0}
        <div class="dash-cc-empty" style="padding:32px 0">No member activity yet.</div>
      {:else}
        <table>
          <thead>
            <tr>
              <th>Member</th>
              <th>Email</th>
              <th class="r">Joined</th>
            </tr>
          </thead>
          <tbody>
            {#each recentMembers as row}
              <tr>
                <td>{row.title || row.name || 'Member'}</td>
                <td>{row.email || '—'}</td>
                <td class="r">{row.updated_at ? timeAgo(row.updated_at) : '—'}</td>
              </tr>
            {/each}
          </tbody>
        </table>
      {/if}
    {/if}
  </div>
</div>
