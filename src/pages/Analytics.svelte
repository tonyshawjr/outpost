<script>
  import { currentRoute, navigate } from '$lib/stores.js';
  import AnalyticsTraffic from '$components/analytics/AnalyticsTraffic.svelte';
  import AnalyticsEvents from '$components/analytics/AnalyticsEvents.svelte';
  import AnalyticsGoals from '$components/analytics/AnalyticsGoals.svelte';

  // ── Layout: full-width, no right sidebar ──────────────────
  $effect(() => {
    const layout = document.querySelector('.app-layout');
    if (layout) layout.classList.add('no-right-sidebar');
    return () => {
      if (layout) layout.classList.remove('no-right-sidebar');
    };
  });

  let route = $derived($currentRoute);
  let activeTab = $derived(
    route === 'analytics-events' ? 'events' :
    route === 'analytics-goals' ? 'goals' : 'traffic'
  );

  const TABS = [
    { key: 'traffic', label: 'Traffic', route: 'analytics' },
    { key: 'events',  label: 'Events',  route: 'analytics-events' },
    { key: 'goals',   label: 'Goals',   route: 'analytics-goals' },
  ];
</script>

<div class="analytics">
  <div class="dash-header">
    <div>
      <h1 class="dash-greeting">Analytics</h1>
      <p class="dash-subtitle">Track your site's performance.</p>
    </div>
  </div>

  <div class="analytics-tabs">
    {#each TABS as tab}
      <button
        class="analytics-tab"
        class:active={activeTab === tab.key}
        onclick={() => navigate(tab.route)}
      >{tab.label}</button>
    {/each}
  </div>

  {#if activeTab === 'traffic'}
    <AnalyticsTraffic />
  {:else if activeTab === 'events'}
    <AnalyticsEvents />
  {:else if activeTab === 'goals'}
    <AnalyticsGoals />
  {/if}
</div>

<style>
  .analytics {
    max-width: var(--content-width-wide);
  }

  .dash-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 24px;
    margin-bottom: 24px;
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

  /* ── Tab Bar ────────────────────────────────────────────── */
  .analytics-tabs {
    display: flex;
    gap: 0;
    border-bottom: 1px solid var(--border-light);
    margin-bottom: 32px;
  }

  .analytics-tab {
    background: none;
    border: none;
    border-bottom: 2px solid transparent;
    padding: 10px 16px;
    font-size: 14px;
    font-family: inherit;
    font-weight: 500;
    color: var(--text-muted);
    cursor: pointer;
    transition: color 0.15s, border-color 0.15s;
  }

  .analytics-tab:hover {
    color: var(--text);
  }

  .analytics-tab.active {
    color: var(--text);
    border-bottom-color: var(--forest);
  }
</style>
