<script>
  import { onMount } from 'svelte';
  import { analytics as analyticsApi, goals as goalsApi } from '$lib/api.js';
  import { addToast } from '$lib/stores.js';

  // ── State ──────────────────────────────────────────────────
  let period = $state('30days');
  let goalsData = $state(null);
  let goalsList = $state(null);
  let loading = $state(true);
  let showForm = $state(false);
  let editingId = $state(null);
  let formName = $state('');
  let formType = $state('pagevisit');
  let formTarget = $state('');
  let saving = $state(false);
  let deleting = $state(null);

  const PERIODS = [
    { val: '7days',    label: 'Last 7 days' },
    { val: '30days',   label: 'Last 30 days' },
    { val: '90days',   label: 'Last 90 days' },
    { val: '12months', label: 'Last 12 months' },
  ];

  // ── Derived ────────────────────────────────────────────────
  let mergedGoals = $derived.by(() => {
    if (!goalsList) return [];
    const statsMap = {};
    if (goalsData?.goals) {
      for (const g of goalsData.goals) {
        statsMap[g.id] = g;
      }
    }
    return goalsList.map(g => ({
      ...g,
      conversions: statsMap[g.id]?.conversions ?? null,
      unique_conversions: statsMap[g.id]?.unique_conversions ?? null,
      conversion_rate: statsMap[g.id]?.conversion_rate ?? null,
      prev_conversions: statsMap[g.id]?.prev_conversions ?? null,
      change: statsMap[g.id]?.change ?? null,
      chart: statsMap[g.id]?.chart ?? [],
    }));
  });

  // ── Helpers ────────────────────────────────────────────────
  function fmtNum(n) {
    if (n == null) return '\u2014';
    if (n >= 1000000) return (n / 1000000).toFixed(1) + 'M';
    if (n >= 1000) return (n / 1000).toFixed(1) + 'K';
    return String(n);
  }

  function fmtPct(rate) {
    if (rate == null) return '\u2014';
    return (rate * 100).toFixed(1) + '%';
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

  function typeLabel(type) {
    if (type === 'pagevisit') return 'Page Visit';
    if (type === 'event') return 'Event';
    return type;
  }

  // ── Data Loading ───────────────────────────────────────────
  async function loadAll(p) {
    loading = true;
    try {
      const [goalsRes, statsRes] = await Promise.allSettled([
        goalsApi.list(),
        analyticsApi.goals(p),
      ]);
      goalsList = goalsRes.status === 'fulfilled' ? goalsRes.value.goals : [];
      goalsData = statsRes.status === 'fulfilled' ? statsRes.value.data : null;
    } catch {
      addToast('Failed to load goals', 'error');
    } finally {
      loading = false;
    }
  }

  async function changePeriod(p) {
    period = p;
    await loadAll(p);
  }

  // ── Form ───────────────────────────────────────────────────
  function openCreate() {
    editingId = null;
    formName = '';
    formType = 'pagevisit';
    formTarget = '';
    showForm = true;
  }

  function openEdit(goal) {
    editingId = goal.id;
    formName = goal.name;
    formType = goal.type;
    formTarget = goal.target;
    showForm = true;
  }

  function cancelForm() {
    showForm = false;
    editingId = null;
    formName = '';
    formType = 'pagevisit';
    formTarget = '';
  }

  async function saveGoal(e) {
    e.preventDefault();
    if (!formName.trim() || !formTarget.trim()) {
      addToast('Name and target are required', 'error');
      return;
    }
    saving = true;
    try {
      if (editingId) {
        await goalsApi.update(editingId, { name: formName.trim(), type: formType, target: formTarget.trim() });
        addToast('Goal updated', 'success');
      } else {
        await goalsApi.create({ name: formName.trim(), type: formType, target: formTarget.trim() });
        addToast('Goal created', 'success');
      }
      cancelForm();
      await loadAll(period);
    } catch {
      addToast('Failed to save goal', 'error');
    } finally {
      saving = false;
    }
  }

  // ── Toggle Active ──────────────────────────────────────────
  async function toggleActive(goal) {
    try {
      await goalsApi.update(goal.id, { active: goal.active ? 0 : 1 });
      await loadAll(period);
    } catch {
      addToast('Failed to update goal', 'error');
    }
  }

  // ── Delete (two-step) ─────────────────────────────────────
  async function handleDelete(goal) {
    if (deleting !== goal.id) {
      deleting = goal.id;
      return;
    }
    try {
      await goalsApi.delete(goal.id);
      addToast('Goal deleted', 'success');
      deleting = null;
      await loadAll(period);
    } catch {
      addToast('Failed to delete goal', 'error');
    }
  }

  onMount(() => loadAll(period));
</script>

<div class="goals-tab">

  <!-- Header: period selector + create button -->
  <div class="goals-header">
    <div class="period-selector">
      {#each PERIODS as p}
        <button
          class="period-btn"
          class:active={period === p.val}
          onclick={() => changePeriod(p.val)}
        >{p.label}</button>
      {/each}
    </div>
    <button class="btn btn-primary" onclick={openCreate}>Create Goal</button>
  </div>

  {#if loading}
    <!-- Loading Skeleton -->
    <div class="goals-skeleton">
      {#each [1, 2, 3, 4] as _}
        <div class="skel-row">
          <div class="skel skel-name"></div>
          <div class="skel skel-type"></div>
          <div class="skel skel-target"></div>
          <div class="skel skel-num"></div>
          <div class="skel skel-num-sm"></div>
          <div class="skel skel-toggle"></div>
          <div class="skel skel-actions"></div>
        </div>
      {/each}
    </div>

  {:else if !goalsList?.length && !showForm}
    <!-- Empty State -->
    <div class="goals-empty">
      <p>No goals yet. Create your first goal to track conversions.</p>
      <button class="btn btn-primary" onclick={openCreate}>Create Goal</button>
    </div>

  {:else}
    <!-- Create/Edit Form (inline at top) -->
    {#if showForm}
      <form class="goal-form" onsubmit={saveGoal}>
        <div class="form-row">
          <div class="form-field">
            <label class="form-label">Goal Name</label>
            <input
              type="text"
              class="form-input"
              bind:value={formName}
              placeholder="e.g. Signup completion"
            />
          </div>
          <div class="form-field form-field-type">
            <label class="form-label">Type</label>
            <div class="type-toggle">
              <button
                type="button"
                class="type-btn"
                class:active={formType === 'pagevisit'}
                onclick={() => formType = 'pagevisit'}
              >Page Visit</button>
              <button
                type="button"
                class="type-btn"
                class:active={formType === 'event'}
                onclick={() => formType = 'event'}
              >Event</button>
            </div>
          </div>
          <div class="form-field">
            <label class="form-label">Target</label>
            <input
              type="text"
              class="form-input"
              bind:value={formTarget}
              placeholder={formType === 'pagevisit' ? '/thank-you' : 'form_submit'}
            />
          </div>
        </div>
        <div class="form-actions">
          <button type="submit" class="btn btn-primary" disabled={saving}>
            {saving ? 'Saving...' : (editingId ? 'Update Goal' : 'Save Goal')}
          </button>
          <button type="button" class="btn btn-secondary" onclick={cancelForm}>Cancel</button>
        </div>
      </form>
    {/if}

    <!-- Goals Table -->
    {#if mergedGoals.length}
      <div class="goals-table">
        <div class="goals-head">
          <span class="col-name">Name</span>
          <span class="col-type">Type</span>
          <span class="col-target">Target</span>
          <span class="col-conversions">Conversions</span>
          <span class="col-rate">Rate</span>
          <span class="col-active">Active</span>
          <span class="col-actions"></span>
        </div>
        {#each mergedGoals as goal (goal.id)}
          <div class="goals-row" class:inactive={!goal.active}>
            <span class="col-name">{goal.name}</span>
            <span class="col-type">{typeLabel(goal.type)}</span>
            <span class="col-target"><code>{goal.target}</code></span>
            <span class="col-conversions">
              <span class="conv-num">{fmtNum(goal.conversions)}</span>
              {#if goal.change != null}
                <span class={`conv-trend ${trendClass(goal.change)}`}>{trendBadge(goal.change)}</span>
              {/if}
            </span>
            <span class="col-rate">{fmtPct(goal.conversion_rate)}</span>
            <span class="col-active">
              <button
                class="toggle"
                class:on={!!goal.active}
                onclick={() => toggleActive(goal)}
                aria-label={goal.active ? 'Deactivate goal' : 'Activate goal'}
              ></button>
            </span>
            <span class="col-actions">
              <button class="action-btn" onclick={() => openEdit(goal)}>Edit</button>
              <button
                class="action-btn action-delete"
                onclick={() => handleDelete(goal)}
                onblur={() => { if (deleting === goal.id) deleting = null; }}
              >
                {deleting === goal.id ? 'Confirm?' : 'Delete'}
              </button>
            </span>
          </div>
        {/each}
      </div>
    {/if}
  {/if}
</div>

<style>
  .goals-tab {
    /* inherits max-width from parent shell */
  }

  /* ── Header ─────────────────────────────────────────────── */
  .goals-header {
    display: flex;
    justify-content: flex-end;
    align-items: center;
    gap: 12px;
    margin-bottom: 28px;
  }

  /* ── Period Selector ────────────────────────────────────── */
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
    box-shadow: 0 1px 3px rgba(45, 42, 38, 0.08);
  }

  /* ── Empty State ────────────────────────────────────────── */
  .goals-empty {
    padding: 64px 0;
    text-align: center;
  }
  .goals-empty p {
    font-size: 14px;
    color: var(--text-light);
    margin: 0 0 20px;
  }

  /* ── Inline Form ────────────────────────────────────────── */
  .goal-form {
    padding: 20px 0 28px;
    margin-bottom: 24px;
    border-bottom: 1px solid var(--border-light);
  }

  .form-row {
    display: grid;
    grid-template-columns: 1fr auto 1fr;
    gap: 20px;
    align-items: end;
  }

  .form-field {
    display: flex;
    flex-direction: column;
    gap: 6px;
  }

  .form-label {
    font-size: 11px;
    font-weight: 500;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--text-muted);
  }

  .form-input {
    background: none;
    border: 1px solid transparent;
    border-radius: var(--radius-sm);
    padding: 8px 10px;
    font-size: 14px;
    font-family: inherit;
    color: var(--text);
    outline: none;
    transition: border-color 0.15s;
  }
  .form-input:hover { border-color: var(--border-light); }
  .form-input:focus { border-color: var(--forest); }
  .form-input::placeholder { color: var(--text-light); }

  .type-toggle {
    display: flex;
    gap: 0;
  }

  .type-btn {
    background: none;
    border: none;
    border-bottom: 2px solid transparent;
    padding: 8px 14px;
    font-size: 14px;
    font-family: inherit;
    color: var(--text-muted);
    cursor: pointer;
    transition: color 0.15s, border-color 0.15s;
  }
  .type-btn:hover { color: var(--text); }
  .type-btn.active {
    color: var(--forest);
    border-bottom-color: var(--forest);
  }

  .form-actions {
    display: flex;
    gap: 8px;
    margin-top: 16px;
  }

  /* ── Goals Table ────────────────────────────────────────── */
  .goals-table {
    width: 100%;
  }

  .goals-head {
    display: grid;
    grid-template-columns: 1.5fr 0.8fr 1.2fr 1fr 0.6fr 0.5fr 0.8fr;
    gap: 12px;
    padding: 0 0 10px;
    border-bottom: 1px solid var(--border-light);
  }

  .goals-head span {
    font-size: 11px;
    font-weight: 500;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--text-muted);
  }

  .goals-row {
    display: grid;
    grid-template-columns: 1.5fr 0.8fr 1.2fr 1fr 0.6fr 0.5fr 0.8fr;
    gap: 12px;
    padding: 12px 0;
    align-items: center;
    border-bottom: 1px solid var(--border-light);
    font-size: 14px;
    color: var(--text);
  }
  .goals-row:last-child { border-bottom: none; }
  .goals-row.inactive { opacity: 0.5; }

  .col-name { font-weight: 500; }
  .col-type { color: var(--text-secondary); }

  .col-target code {
    font-family: 'SF Mono', 'Fira Code', monospace;
    font-size: 13px;
    color: var(--text-muted);
  }

  .col-conversions {
    display: flex;
    align-items: center;
    gap: 8px;
  }

  .conv-num { font-weight: 500; }

  .conv-trend {
    font-size: 12px;
  }
  .conv-trend.trend-up { color: var(--forest); }
  .conv-trend.trend-down { color: #c44; }
  .conv-trend.trend-flat { color: var(--text-light); }

  .col-rate { color: var(--text-secondary); }

  /* ── Toggle Switch ──────────────────────────────────────── */
  .toggle {
    position: relative;
    width: 36px;
    height: 20px;
    background: var(--border);
    border-radius: 99px;
    cursor: pointer;
    transition: background 0.2s;
    border: none;
    flex-shrink: 0;
  }
  .toggle.on { background: var(--forest); }
  .toggle::after {
    content: '';
    position: absolute;
    top: 2px;
    left: 2px;
    width: 16px;
    height: 16px;
    background: white;
    border-radius: 50%;
    transition: transform 0.2s;
  }
  .toggle.on::after { transform: translateX(16px); }

  /* ── Action Buttons ─────────────────────────────────────── */
  .col-actions {
    display: flex;
    gap: 8px;
    justify-content: flex-end;
  }

  .action-btn {
    background: none;
    border: none;
    font-size: 13px;
    font-family: inherit;
    color: var(--text-muted);
    cursor: pointer;
    padding: 2px 4px;
    transition: color 0.15s;
  }
  .action-btn:hover { color: var(--text); }
  .action-delete:hover, .action-delete.confirm { color: #c44; }

  /* ── Skeleton ───────────────────────────────────────────── */
  .goals-skeleton {
    display: flex;
    flex-direction: column;
    gap: 16px;
  }

  .skel-row {
    display: grid;
    grid-template-columns: 1.5fr 0.8fr 1.2fr 1fr 0.6fr 0.5fr 0.8fr;
    gap: 12px;
    align-items: center;
  }

  .skel {
    background: var(--bg-hover);
    border-radius: 4px;
    animation: pulse 1.5s ease-in-out infinite;
  }

  .skel-name    { height: 14px; width: 80%; }
  .skel-type    { height: 14px; width: 60%; }
  .skel-target  { height: 14px; width: 70%; }
  .skel-num     { height: 14px; width: 50%; }
  .skel-num-sm  { height: 14px; width: 40%; }
  .skel-toggle  { height: 20px; width: 36px; border-radius: 99px; }
  .skel-actions { height: 14px; width: 60%; }

  @keyframes pulse {
    0%, 100% { opacity: 1; }
    50%      { opacity: 0.4; }
  }

  /* ── Responsive ─────────────────────────────────────────── */
  @media (max-width: 900px) {
    .goals-head { display: none; }
    .goals-row {
      grid-template-columns: 1fr 1fr;
      gap: 8px;
      padding: 16px 0;
    }
    .col-type, .col-target { font-size: 13px; }
    .goals-header { flex-wrap: wrap; }
    .form-row { grid-template-columns: 1fr; }
    .skel-row { grid-template-columns: 1fr 1fr; }
  }
</style>
