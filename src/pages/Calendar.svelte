<script>
  import { onMount } from 'svelte';
  import { calendar as calendarApi } from '$lib/api.js';
  import { collectionsList, navigate } from '$lib/stores.js';

  // ── Layout: full-width, no right sidebar ──────────────────
  $effect(() => {
    const layout = document.querySelector('.app-layout');
    if (layout) layout.classList.add('no-right-sidebar');
    return () => {
      if (layout) layout.classList.remove('no-right-sidebar');
    };
  });

  let colls = $derived($collectionsList);

  // ── State ──────────────────────────────────────────────────
  let loading = $state(true);
  let items = $state([]);
  let filterCollection = $state('');
  let today = new Date();
  let currentYear = $state(today.getFullYear());
  let currentMonth = $state(today.getMonth()); // 0-indexed

  let monthLabel = $derived(new Date(currentYear, currentMonth, 1).toLocaleDateString('en-US', { month: 'long', year: 'numeric' }));

  // Calendar grid cells
  let calendarDays = $derived.by(() => {
    const first = new Date(currentYear, currentMonth, 1);
    const startDay = first.getDay(); // 0=Sun
    const daysInMonth = new Date(currentYear, currentMonth + 1, 0).getDate();

    const cells = [];
    // Leading blanks
    for (let i = 0; i < startDay; i++) cells.push(null);
    // Actual days
    for (let d = 1; d <= daysInMonth; d++) cells.push(d);
    return cells;
  });

  // Group items by day number
  let itemsByDay = $derived.by(() => {
    const map = {};
    for (const item of items) {
      const dateStr = item.status === 'scheduled' ? item.scheduled_at : item.published_at;
      if (!dateStr) continue;
      const d = new Date(dateStr);
      if (d.getFullYear() === currentYear && d.getMonth() === currentMonth) {
        const day = d.getDate();
        if (!map[day]) map[day] = [];
        map[day].push(item);
      }
    }
    return map;
  });

  // Today's day number (for highlighting)
  let todayDay = $derived(
    today.getFullYear() === currentYear && today.getMonth() === currentMonth ? today.getDate() : null
  );

  const WEEKDAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
  let fetchSeq = 0;

  async function fetchCalendar() {
    const seq = ++fetchSeq;
    loading = true;
    try {
      const start = `${currentYear}-${String(currentMonth + 1).padStart(2, '0')}-01`;
      const endDate = new Date(currentYear, currentMonth + 1, 0);
      const end = `${endDate.getFullYear()}-${String(endDate.getMonth() + 1).padStart(2, '0')}-${String(endDate.getDate()).padStart(2, '0')}`;
      const res = await calendarApi.get(start, end, filterCollection || undefined);
      if (seq !== fetchSeq) return; // discard stale response
      items = res.items || [];
    } catch (e) {
      if (seq !== fetchSeq) return;
      items = [];
    } finally {
      if (seq === fetchSeq) loading = false;
    }
  }

  function prevMonth() {
    if (currentMonth === 0) { currentMonth = 11; currentYear--; }
    else currentMonth--;
  }

  function nextMonth() {
    if (currentMonth === 11) { currentMonth = 0; currentYear++; }
    else currentMonth++;
  }

  function goToday() {
    const now = new Date();
    currentYear = now.getFullYear();
    currentMonth = now.getMonth();
  }

  function statusColor(status) {
    switch (status) {
      case 'published': return '#4A8B72';
      case 'scheduled': return '#5A9BD5';
      case 'pending_review': return '#D97706';
      default: return '#94a3b8';
    }
  }

  function openItem(item) {
    navigate('collection-editor', { collectionSlug: item.collection_slug, itemId: item.id });
  }

  // Fetch on mount + whenever month or filter changes
  $effect(() => {
    // Read reactive deps
    currentYear; currentMonth; filterCollection;
    fetchCalendar();
  });
</script>

<div class="calendar-page">
  <div class="page-header">
    <div class="page-header-icon sage">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
    </div>
    <div class="page-header-content">
      <h1 class="page-title">Calendar</h1>
      <p class="page-subtitle">Scheduled and published content by date</p>
    </div>
  </div>

  <div class="cal-toolbar">
    <div class="cal-nav">
      <button class="cal-nav-btn" onclick={prevMonth} aria-label="Previous month">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
      </button>
      <span class="cal-month-label">{monthLabel}</span>
      <button class="cal-nav-btn" onclick={nextMonth} aria-label="Next month">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
      </button>
      <button class="cal-today-btn" onclick={goToday}>Today</button>
    </div>
    {#if colls.length > 1}
      <select class="cal-filter" bind:value={filterCollection}>
        <option value="">All collections</option>
        {#each colls as c}
          <option value={c.slug}>{c.name}</option>
        {/each}
      </select>
    {/if}
  </div>

  <div class="cal-grid">
    {#each WEEKDAYS as wd}
      <div class="cal-weekday">{wd}</div>
    {/each}

    {#each calendarDays as day}
      {#if day === null}
        <div class="cal-cell cal-cell-empty"></div>
      {:else}
        <div class="cal-cell" class:cal-cell-today={day === todayDay}>
          <span class="cal-day-num">{day}</span>
          <div class="cal-items">
            {#each (itemsByDay[day] || []).slice(0, 3) as item}
              <button class="cal-item-pill" style="--pill-color: {statusColor(item.status)}" onclick={() => openItem(item)} title="{item.title} ({item.collection_name})">
                <span class="cal-pill-dot"></span>
                <span class="cal-pill-text">{item.title}</span>
              </button>
            {/each}
            {#if (itemsByDay[day] || []).length > 3}
              <span class="cal-more">+{(itemsByDay[day] || []).length - 3} more</span>
            {/if}
          </div>
        </div>
      {/if}
    {/each}
  </div>

  {#if loading}
    <div class="cal-loading">Loading...</div>
  {/if}
</div>

<style>
  .calendar-page {
    max-width: var(--content-width-wide, 1100px);
    margin: 0 auto;
    padding: 40px 48px 80px;
  }

  .cal-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 20px;
    gap: 12px;
  }

  .cal-nav {
    display: flex;
    align-items: center;
    gap: 8px;
  }

  .cal-nav-btn {
    background: none;
    border: 1px solid var(--border-light, #e2e8f0);
    border-radius: 6px;
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    color: var(--sec);
    transition: background 0.1s, color 0.1s;
  }

  .cal-nav-btn:hover {
    background: var(--raised);
    color: var(--text);
  }

  .cal-nav-btn svg {
    width: 16px;
    height: 16px;
  }

  .cal-month-label {
    font-size: 16px;
    font-weight: 600;
    min-width: 180px;
    text-align: center;
    color: var(--text);
  }

  .cal-today-btn {
    background: none;
    border: 1px solid var(--border-light, #e2e8f0);
    border-radius: 6px;
    padding: 4px 12px;
    font-size: 12px;
    cursor: pointer;
    color: var(--sec);
    transition: background 0.1s;
  }

  .cal-today-btn:hover {
    background: var(--raised);
  }

  .cal-filter {
    padding: 6px 10px;
    border: 1px solid var(--border-light, #e2e8f0);
    border-radius: 6px;
    font-size: 13px;
    background: var(--bg);
    color: var(--text);
    cursor: pointer;
  }

  .cal-grid {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    border: 1px solid var(--border-light, #e2e8f0);
    border-radius: 8px;
    overflow: hidden;
  }

  .cal-weekday {
    padding: 8px;
    text-align: center;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--text-muted);
    background: var(--raised);
    border-bottom: 1px solid var(--border-light, #e2e8f0);
  }

  .cal-cell {
    min-height: 100px;
    padding: 6px;
    border-right: 1px solid var(--border-light, #e2e8f0);
    border-bottom: 1px solid var(--border-light, #e2e8f0);
    background: var(--bg);
    position: relative;
  }

  .cal-cell:nth-child(7n) {
    border-right: none;
  }

  .cal-cell-empty {
    background: var(--raised);
    opacity: 0.5;
  }

  .cal-cell-today {
    background: var(--bg-accent-subtle, rgba(74, 139, 114, 0.04));
  }

  .cal-cell-today .cal-day-num {
    background: var(--accent, #4A8B72);
    color: #fff;
    border-radius: 50%;
    width: 24px;
    height: 24px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
  }

  .cal-day-num {
    font-size: 12px;
    font-weight: 500;
    color: var(--sec);
    display: inline-block;
    margin-bottom: 4px;
  }

  .cal-items {
    display: flex;
    flex-direction: column;
    gap: 2px;
  }

  .cal-item-pill {
    display: flex;
    align-items: center;
    gap: 4px;
    padding: 2px 6px;
    border: none;
    background: none;
    border-radius: 4px;
    cursor: pointer;
    text-align: left;
    transition: background 0.1s;
    width: 100%;
  }

  .cal-item-pill:hover {
    background: var(--raised);
  }

  .cal-pill-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: var(--pill-color);
    flex-shrink: 0;
  }

  .cal-pill-text {
    font-size: 11px;
    color: var(--text);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    min-width: 0;
  }

  .cal-more {
    font-size: 10px;
    color: var(--text-muted);
    padding: 0 6px;
  }

  .cal-loading {
    text-align: center;
    padding: 40px;
    color: var(--text-muted);
    font-size: 13px;
  }

  @media (max-width: 768px) {
    .calendar-page {
      padding: 20px 16px 60px;
    }

    .cal-toolbar {
      flex-direction: column;
      align-items: stretch;
    }

    .cal-nav {
      justify-content: center;
    }

    .cal-month-label {
      min-width: auto;
    }

    .cal-cell {
      min-height: 60px;
      padding: 4px;
    }

    .cal-pill-text {
      font-size: 10px;
    }
  }
</style>
