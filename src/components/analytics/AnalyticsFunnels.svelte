<script>
  import { onMount } from 'svelte';
  import { analytics as analyticsApi } from '$lib/api.js';
  import { addToast } from '$lib/stores.js';

  let period = $state('30days');
  let data = $state(null);
  let loading = $state(true);

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
    if (rate == null || rate === 0) return '0%';
    return Math.round(rate * 100) + '%';
  }

  function fmtTime(ts) {
    if (!ts) return '—';
    const d = new Date(ts);
    const now = new Date();
    const diff = now - d;
    if (diff < 3600000) return Math.floor(diff / 60000) + 'm ago';
    if (diff < 86400000) return Math.floor(diff / 3600000) + 'h ago';
    return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
  }

  function eventIcon(type) {
    if (type === 'signup') return '→';
    if (type === 'login') return '↩';
    if (type === 'role_change') return '↑';
    return '·';
  }

  function eventLabel(type) {
    if (type === 'signup') return 'Signed up';
    if (type === 'login') return 'Logged in';
    if (type === 'role_change') return 'Role changed';
    if (type === 'email_verified') return 'Email verified';
    return type;
  }

  let maxCount = $derived.by(() => {
    if (!data?.stages) return 1;
    return Math.max(...data.stages.map(s => s.count), 1);
  });

  async function loadData(p = '30days') {
    loading = true;
    try {
      const res = await analyticsApi.funnels(p);
      data = res.data;
    } catch (err) {
      addToast('Failed to load funnel data', 'error');
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

<div class="analytics-funnels">
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
      <div class="skel skel-funnel"></div>
    </div>

  {:else if !data}
    <div class="section-empty">
      Failed to load funnel data.
    </div>

  {:else}
    <!-- Funnel Visualization -->
    <div class="funnel">
      {#each data.stages as stage, i}
        <div class="funnel-stage">
          <div class="funnel-info">
            <span class="funnel-name">{stage.name}</span>
            <span class="funnel-count">{fmtNum(stage.count)}</span>
          </div>
          <div class="funnel-bar-wrap">
            <div
              class="funnel-bar"
              style="width: {maxCount > 0 ? Math.max((stage.count / maxCount) * 100, 2) : 2}%"
            ></div>
          </div>
          {#if i > 0}
            <div class="funnel-rate">
              <span class="rate-value">{fmtPct(stage.rate)}</span>
              <span class="rate-label">conversion</span>
              {#if stage.dropoff > 0}
                <span class="dropoff">↓ {fmtPct(stage.dropoff)} drop-off</span>
              {/if}
            </div>
          {/if}
        </div>
      {/each}
    </div>

    <!-- Recent Member Activity -->
    {#if data.recent_events?.length}
      <div class="activity-section">
        <div class="section-header">
          <span class="split-title">Recent Member Activity</span>
        </div>
        <div class="activity-list">
          {#each data.recent_events as event}
            <div class="activity-row">
              <span class="activity-icon">{eventIcon(event.event_type)}</span>
              <span class="activity-user">{event.username || event.email || 'Unknown'}</span>
              <span class="activity-action">{eventLabel(event.event_type)}</span>
              {#if event.details}
                <span class="activity-detail">{event.details}</span>
              {/if}
              <span class="activity-time">{fmtTime(event.created_at)}</span>
            </div>
          {/each}
        </div>
      </div>
    {:else}
      <div class="section-empty" style="margin-top: 32px;">
        No member activity recorded yet. Events are tracked when members sign up, log in, or change roles.
      </div>
    {/if}
  {/if}
</div>

<style>
  .analytics-funnels {
    padding-top: 0;
  }

  .analytics-funnels > .period-selector {
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

  /* ── Funnel ────────────────────────────────────────────────── */
  .funnel {
    margin-bottom: 48px;
  }

  .funnel-stage {
    margin-bottom: 20px;
  }

  .funnel-info {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    margin-bottom: 8px;
  }

  .funnel-name {
    font-size: 14px;
    font-weight: 600;
    color: var(--text);
  }

  .funnel-count {
    font-family: var(--font-serif);
    font-size: 24px;
    font-weight: 700;
    color: var(--text);
    letter-spacing: -0.02em;
  }

  .funnel-bar-wrap {
    background: var(--bg-hover);
    border-radius: 6px;
    height: 32px;
    overflow: hidden;
  }

  .funnel-bar {
    height: 100%;
    background: var(--forest);
    border-radius: 6px;
    transition: width 0.4s ease;
    min-width: 4px;
  }

  .funnel-stage:nth-child(2) .funnel-bar { opacity: 0.8; }
  .funnel-stage:nth-child(3) .funnel-bar { opacity: 0.6; }
  .funnel-stage:nth-child(4) .funnel-bar { opacity: 0.4; }

  .funnel-rate {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-top: 6px;
    font-size: 12px;
  }

  .rate-value {
    font-weight: 600;
    color: var(--forest);
  }

  .rate-label {
    color: var(--text-muted);
  }

  .dropoff {
    color: var(--error);
    font-weight: 500;
  }

  /* ── Activity Section ──────────────────────────────────────── */
  .activity-section {
    border-top: 1px solid var(--border-light);
    padding-top: 32px;
  }

  .section-header {
    margin-bottom: 20px;
  }

  .split-title {
    font-size: 15px;
    font-weight: 600;
    color: var(--text);
  }

  .activity-list {
    display: flex;
    flex-direction: column;
  }

  .activity-row {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 0;
    border-bottom: 1px solid var(--border-light);
    font-size: 13px;
  }
  .activity-row:last-child { border-bottom: none; }

  .activity-icon {
    font-size: 14px;
    color: var(--forest);
    width: 16px;
    text-align: center;
    flex-shrink: 0;
  }

  .activity-user {
    font-weight: 600;
    color: var(--text);
    flex-shrink: 0;
  }

  .activity-action {
    color: var(--text-secondary);
  }

  .activity-detail {
    color: var(--text-muted);
    font-size: 12px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    max-width: 200px;
  }

  .activity-time {
    margin-left: auto;
    color: var(--text-muted);
    font-size: 12px;
    flex-shrink: 0;
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
  .skel-funnel { height: 300px; }

  @keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.5; }
  }

  /* ── Responsive ────────────────────────────────────────────── */
  @media (max-width: 600px) {
    .period-selector { flex-wrap: wrap; }
    .activity-detail { display: none; }
  }
</style>
