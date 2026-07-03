<script>
  import { onMount } from 'svelte';
  import { editorial, collections } from '$lib/api.js';
  import { addToast } from '$lib/stores.js';
  import { Plus, Play, Trash2, Sparkles, Search } from 'lucide-svelte';
  import Checkbox from '$components/Checkbox.svelte';

  let jobs = $state([]);
  let runs = $state([]);
  let collectionsList = $state([]);
  let budget = $state({ daily_cap_cents: 500, spent_today_cents: 0, remaining_cents: 500 });
  let loading = $state(true);
  let editing = $state(null);
  let creating = $state(false);
  let activeTab = $state('jobs');

  let findText = $state('');
  let replaceText = $state('');
  let findCollection = $state('');
  let findResult = $state(null);
  let findRunning = $state(false);

  async function loadAll() {
    loading = true;
    try {
      const [j, r, b, c] = await Promise.all([
        editorial.jobsList(),
        editorial.runsList(),
        editorial.budgetGet(),
        collections.list(),
      ]);
      jobs = j.jobs || [];
      runs = r.runs || [];
      budget = b;
      collectionsList = c.collections || [];
    } catch (e) {
      addToast(e.message || 'Failed to load editorial data', 'error');
    } finally {
      loading = false;
    }
  }

  onMount(loadAll);

  function startCreate() {
    editing = {
      name: '',
      description: '',
      cadence: 'daily',
      collection_slug: collectionsList[0]?.slug || '',
      prompt: '',
      target_status: 'draft',
      cost_cap_cents: 50,
      enabled: true,
    };
    creating = true;
  }

  function startEdit(j) {
    editing = JSON.parse(JSON.stringify(j));
    creating = false;
  }

  async function save() {
    try {
      if (creating) {
        await editorial.jobCreate(editing);
        addToast('Job created', 'success');
      } else {
        await editorial.jobUpdate(editing.id, editing);
        addToast('Job saved', 'success');
      }
      editing = null;
      creating = false;
      await loadAll();
    } catch (e) {
      addToast(e.message || 'Save failed', 'error');
    }
  }

  async function runNow(j) {
    try {
      const r = await editorial.jobRunNow(j.id);
      addToast(`Run queued (id ${r.run_id})`, 'success');
      setTimeout(loadAll, 600);
    } catch (e) {
      addToast(e.message || 'Run failed', 'error');
    }
  }

  async function remove(j) {
    if (!confirm(`Delete job "${j.name}"? Past runs are kept but the schedule will stop.`)) return;
    try {
      await editorial.jobDelete(j.id);
      addToast('Job deleted', 'success');
      await loadAll();
    } catch (e) {
      addToast(e.message || 'Delete failed', 'error');
    }
  }

  async function saveBudget() {
    try {
      await editorial.budgetUpdate(budget.daily_cap_cents);
      addToast('Daily budget updated', 'success');
      await loadAll();
    } catch (e) {
      addToast(e.message || 'Save failed', 'error');
    }
  }

  async function runFindAndUpdate(dryRun = false) {
    if (!findText || findText.length < 2) {
      addToast('Find string is too short (min 2 chars)', 'error');
      return;
    }
    if (!confirm(`Replace every "${findText}" with "${replaceText}" across ${findCollection || 'all collections'}? This is logged in revisions but not auto-reverted.`)) return;
    findRunning = true;
    try {
      const r = await editorial.findAndUpdate(findText, replaceText, findCollection || null);
      findResult = r;
      addToast(`Replaced ${r.matched} match(es) across ${r.updated_items.length} item(s)`, 'success');
    } catch (e) {
      addToast(e.message || 'Replacement failed', 'error');
    } finally {
      findRunning = false;
    }
  }

  function fmtSpend(cents) {
    return '$' + (cents / 100).toFixed(2);
  }

  function fmtTime(ts) {
    if (!ts) return '—';
    return new Date(ts.replace(' ', 'T') + 'Z').toLocaleString();
  }
</script>

<div class="page">
  <header class="page-head">
    <div>
      <h1><Sparkles size={22}/> Editorial AI</h1>
      <p class="muted">AI as a member of the content team — set the cadence; it ships drafts. Always summoned or scheduled, never ambient.</p>
    </div>
  </header>

  <div class="budget">
    <div class="budget-stat">
      <div class="lbl">Daily budget</div>
      <div class="val">{fmtSpend(budget.daily_cap_cents)}</div>
    </div>
    <div class="budget-stat">
      <div class="lbl">Spent today</div>
      <div class="val">{fmtSpend(budget.spent_today_cents)}</div>
    </div>
    <div class="budget-stat">
      <div class="lbl">Remaining</div>
      <div class="val">{fmtSpend(budget.remaining_cents)}</div>
    </div>
    <div class="budget-set">
      <label class="lbl">New cap</label>
      <input class="inp" type="number" min="0" max="100000" bind:value={budget.daily_cap_cents}/>
      <button class="btn-secondary" onclick={saveBudget}>Save cap</button>
    </div>
  </div>

  <div class="tabs">
    <button class="tab" class:active={activeTab === 'jobs'} onclick={() => activeTab = 'jobs'}>Jobs</button>
    <button class="tab" class:active={activeTab === 'runs'} onclick={() => activeTab = 'runs'}>Run history</button>
    <button class="tab" class:active={activeTab === 'find'} onclick={() => activeTab = 'find'}>Find &amp; replace</button>
  </div>

  {#if activeTab === 'jobs'}
    <div class="section-head">
      <h3>Scheduled jobs</h3>
      <button class="btn-primary" onclick={startCreate}><Plus size={14}/> New job</button>
    </div>
    {#if loading}
      <div class="loading">Loading…</div>
    {:else if !jobs.length}
      <div class="empty">No jobs yet. Create one to schedule recurring AI drafts.</div>
    {:else}
      <table class="tbl">
        <thead>
          <tr>
            <th>Name</th><th>Cadence</th><th>Collection</th><th>Cost cap</th><th>Last run</th><th>Next run</th><th></th>
          </tr>
        </thead>
        <tbody>
          {#each jobs as j (j.id)}
            <tr onclick={() => startEdit(j)}>
              <td>
                {j.name}
                {#if !j.enabled}<span class="pill">paused</span>{/if}
              </td>
              <td>{j.cadence}</td>
              <td>{j.collection_slug}</td>
              <td>{fmtSpend(j.cost_cap_cents)}</td>
              <td>{fmtTime(j.last_run_at)}</td>
              <td>{fmtTime(j.next_run_at)}</td>
              <td class="row-actions" onclick={(e) => e.stopPropagation()}>
                <button class="btn-iconic" onclick={() => runNow(j)} aria-label="Run now"><Play size={13}/></button>
                <button class="btn-iconic danger" onclick={() => remove(j)} aria-label="Delete"><Trash2 size={13}/></button>
              </td>
            </tr>
          {/each}
        </tbody>
      </table>
    {/if}
  {:else if activeTab === 'runs'}
    <div class="section-head"><h3>Recent runs</h3></div>
    {#if !runs.length}
      <div class="empty">No runs yet.</div>
    {:else}
      <table class="tbl">
        <thead>
          <tr><th>Started</th><th>Job</th><th>Status</th><th>Cost</th><th>Item</th><th>Notes</th></tr>
        </thead>
        <tbody>
          {#each runs as r (r.id)}
            <tr>
              <td>{fmtTime(r.started_at)}</td>
              <td>{r.job_name || '—'}</td>
              <td><span class="pill status-{r.status}">{r.status}</span></td>
              <td>{fmtSpend(r.cost_cents || 0)}</td>
              <td>{r.created_item_id ? `#${r.created_item_id}` : '—'}</td>
              <td class="muted-cell">{r.error || r.log || ''}</td>
            </tr>
          {/each}
        </tbody>
      </table>
    {/if}
  {:else if activeTab === 'find'}
    <div class="section-head">
      <h3><Search size={16}/> Find &amp; replace</h3>
      <p class="muted small">Walks every string field in collection_items.data. Each change is logged to revisions for recovery.</p>
    </div>
    <div class="find-form">
      <label class="lbl">Find</label>
      <input class="inp" bind:value={findText} placeholder="apply now"/>
      <label class="lbl">Replace with</label>
      <input class="inp" bind:value={replaceText} placeholder="enroll today"/>
      <label class="lbl">Limit to collection (optional)</label>
      <select class="inp" bind:value={findCollection}>
        <option value="">All collections</option>
        {#each collectionsList as c}
          <option value={c.slug}>{c.name}</option>
        {/each}
      </select>
      <div class="find-actions">
        <button class="btn-primary" onclick={runFindAndUpdate} disabled={findRunning || !findText || findText.length < 2}>
          {findRunning ? 'Running…' : 'Run replacement'}
        </button>
      </div>
      {#if findResult}
        <div class="find-result">
          Replaced <strong>{findResult.matched}</strong> occurrence{findResult.matched === 1 ? '' : 's'} across <strong>{findResult.updated_items.length}</strong> item{findResult.updated_items.length === 1 ? '' : 's'}.
        </div>
      {/if}
    </div>
  {/if}
</div>

{#if editing}
  <div class="overlay" onclick={() => editing = null} role="presentation">
    <div class="sheet" onclick={(e) => e.stopPropagation()} role="dialog" aria-modal="true">
      <header class="sheet-head"><h2>{creating ? 'New editorial job' : `Edit — ${editing.name}`}</h2><button class="x" onclick={() => editing = null}>×</button></header>
      <div class="sheet-body">
        <label class="lbl">Name</label>
        <input class="inp" bind:value={editing.name} placeholder="Monday blog drafts" />

        <label class="lbl">Description</label>
        <textarea class="inp" rows="2" bind:value={editing.description}></textarea>

        <div class="grid-2">
          <div>
            <label class="lbl">Cadence</label>
            <select class="inp" bind:value={editing.cadence}>
              <option value="manual">Manual (run on demand only)</option>
              <option value="hourly">Hourly</option>
              <option value="daily">Daily</option>
              <option value="weekly">Weekly</option>
            </select>
          </div>
          <div>
            <label class="lbl">Target collection</label>
            <select class="inp" bind:value={editing.collection_slug}>
              {#each collectionsList as c}
                <option value={c.slug}>{c.name} ({c.slug})</option>
              {/each}
            </select>
          </div>
        </div>

        <div class="grid-2">
          <div>
            <label class="lbl">Cost cap (cents per run)</label>
            <input class="inp" type="number" min="1" max="10000" bind:value={editing.cost_cap_cents}/>
          </div>
          <div>
            <label class="lbl">Status when created</label>
            <select class="inp" bind:value={editing.target_status}>
              <option value="draft">Draft</option>
              <option value="published">Published</option>
            </select>
          </div>
        </div>

        <label class="lbl">Prompt</label>
        <textarea class="inp" rows="6" bind:value={editing.prompt} placeholder="Write three short blog post drafts on topics relevant to a coastal insurance agency. Match the brand voice: warm, plain-language, never sales-y."></textarea>

        <Checkbox bind:checked={editing.enabled} label="Enabled" />
      </div>
      <footer class="sheet-foot">
        <button class="btn-secondary" onclick={() => editing = null}>Cancel</button>
        <button class="btn-primary" onclick={save} disabled={!editing.name || !editing.prompt || !editing.collection_slug}>Save</button>
      </footer>
    </div>
  </div>
{/if}

<style>
  .page { padding: 32px 40px; max-width: 1100px; }
  .page-head h1 { font-family: var(--font-serif, Georgia, serif); font-size: 32px; margin: 0 0 4px; font-weight: 500; display: flex; align-items: center; gap: 10px; }
  .muted { color: var(--text-tertiary); font-size: 14px; line-height: 1.5; max-width: 60ch; }
  .muted.small { font-size: 12.5px; }
  .budget { display: grid; grid-template-columns: repeat(3, max-content) 1fr; gap: 32px; align-items: end; padding: 18px 22px; background: var(--bg-secondary); border-radius: 10px; margin: 22px 0 28px; }
  .budget-stat .lbl, .budget-set .lbl { font-size: 11px; text-transform: uppercase; letter-spacing: 0.06em; color: var(--text-tertiary); margin-bottom: 6px; display: block; }
  .budget-stat .val { font-family: var(--font-serif, Georgia, serif); font-size: 22px; }
  .budget-set { display: grid; grid-template-columns: 1fr max-content; gap: 8px; align-items: end; }
  .budget-set .lbl { grid-column: 1 / -1; }

  .tabs { display: flex; gap: 4px; border-bottom: 1px solid var(--border-secondary); margin-bottom: 24px; }
  .tab { background: none; border: none; padding: 10px 14px; color: var(--text-tertiary); cursor: pointer; font-size: 13px; border-bottom: 2px solid transparent; margin-bottom: -1px; }
  .tab.active { color: var(--text-primary); border-bottom-color: var(--accent); }

  .section-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; gap: 16px; }
  .section-head h3 { font-size: 14px; margin: 0; font-weight: 500; display: flex; align-items: center; gap: 8px; }

  .tbl { width: 100%; border-collapse: collapse; font-size: 13px; }
  .tbl th { text-align: left; font-weight: 500; font-size: 11px; text-transform: uppercase; letter-spacing: 0.06em; color: var(--text-tertiary); padding: 10px 12px; border-bottom: 1px solid var(--border-secondary); }
  .tbl td { padding: 12px; border-bottom: 1px solid var(--border-secondary); }
  .tbl tbody tr { cursor: pointer; }
  .tbl tbody tr:hover { background: var(--bg-secondary); }
  .row-actions { width: 80px; text-align: right; cursor: default; }
  .btn-iconic { background: none; border: none; color: var(--text-tertiary); cursor: pointer; padding: 4px; border-radius: 4px; }
  .btn-iconic.danger:hover { color: var(--accent-danger, #e05252); }
  .btn-iconic:hover { color: var(--text-primary); }
  .pill { display: inline-flex; padding: 2px 7px; font-size: 10px; text-transform: uppercase; letter-spacing: 0.06em; color: var(--text-tertiary); border: 1px solid var(--border-secondary); border-radius: 3px; margin-left: 6px; }
  .pill.status-completed { color: var(--accent, #4f9b6e); border-color: currentColor; }
  .pill.status-failed    { color: #c25151; border-color: currentColor; }
  .pill.status-skipped   { color: #b08a3c; border-color: currentColor; }
  .pill.status-running   { color: var(--text-tertiary); }
  .muted-cell { color: var(--text-tertiary); font-size: 12px; max-width: 320px; }

  .empty, .loading { color: var(--text-tertiary); padding: 32px 0; }

  .find-form { max-width: 560px; }
  .find-form .lbl { display: block; font-size: 11px; text-transform: uppercase; letter-spacing: 0.06em; color: var(--text-tertiary); margin: 14px 0 6px; }
  .find-actions { margin-top: 18px; }
  .find-result { margin-top: 16px; padding: 12px 14px; background: var(--bg-secondary); border-radius: 6px; font-size: 13px; }

  .inp { width: 100%; padding: 9px 12px; border: 1px solid transparent; border-radius: 6px; background: var(--bg-tertiary); font-size: 14px; color: var(--text-primary); outline: none; box-sizing: border-box; transition: border-color 0.15s; font-family: inherit; }
  .inp:hover { border-color: var(--border-secondary); }
  .inp:focus { border-color: var(--accent); }
  .check { display: flex; align-items: center; gap: 8px; margin-top: 14px; font-size: 13px; }

  .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }

  .overlay { position: fixed; inset: 0; background: rgba(10, 10, 10, 0.55); display: flex; align-items: center; justify-content: center; z-index: 100; padding: 24px; }
  .sheet { background: var(--bg-primary); border-radius: 10px; max-width: 680px; width: 100%; max-height: 90vh; display: flex; flex-direction: column; overflow: hidden; box-shadow: 0 20px 60px rgba(0,0,0,0.4); }
  .sheet-head { padding: 18px 24px; border-bottom: 1px solid var(--border-secondary); display: flex; justify-content: space-between; align-items: center; }
  .sheet-head h2 { font-size: 16px; margin: 0; font-weight: 500; }
  .x { background: none; border: none; font-size: 24px; line-height: 1; color: var(--text-tertiary); cursor: pointer; }
  .sheet-body { padding: 18px 24px; overflow-y: auto; flex: 1; }
  .sheet-foot { padding: 14px 24px; border-top: 1px solid var(--border-secondary); display: flex; justify-content: flex-end; gap: 8px; }

  .lbl { display: block; font-size: 11px; text-transform: uppercase; letter-spacing: 0.06em; color: var(--text-tertiary); margin: 12px 0 6px; }
  .btn-primary { display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; background: var(--text-primary); color: var(--bg-primary); border: none; border-radius: 6px; font-size: 13px; font-weight: 500; cursor: pointer; }
  .btn-primary:disabled { opacity: 0.4; cursor: not-allowed; }
  .btn-secondary { padding: 8px 14px; background: none; border: 1px solid var(--border-secondary); color: var(--text-primary); border-radius: 6px; font-size: 13px; cursor: pointer; }
</style>
