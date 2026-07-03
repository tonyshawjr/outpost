<script>
  import { onMount } from 'svelte';
  import { newsletter as nlApi } from '$lib/api.js';
  import { addToast } from '$lib/stores.js';
  import RichTextEditor from '$components/RichTextEditor.svelte';

  let loading = $state(true);
  let counts = $state({ confirmed: 0, pending: 0, unsubscribed: 0 });
  let memberOptins = $state(0);
  let totalRecipients = $state(0);
  let subscribers = $state([]);

  let subject = $state('');
  let contentHtml = $state('');
  let testEmail = $state('');
  let sending = $state(false);
  let confirmingSend = $state(false);

  async function load() {
    loading = true;
    try {
      const data = await nlApi.subscribers();
      counts = data.counts || counts;
      memberOptins = data.member_optins || 0;
      totalRecipients = data.total_recipients || 0;
      subscribers = data.subscribers || [];
    } catch (e) {
      addToast(e.message || 'Failed to load subscribers', 'error');
    } finally {
      loading = false;
    }
  }

  onMount(load);

  async function sendTest() {
    if (!subject.trim() || !contentHtml.trim()) { addToast('Add a subject and content first', 'error'); return; }
    if (!testEmail.trim()) { addToast('Enter a test email address', 'error'); return; }
    sending = true;
    try {
      await nlApi.send({ subject, html: contentHtml, test: true, test_email: testEmail.trim() });
      addToast(`Test sent to ${testEmail}`, 'success');
    } catch (e) {
      addToast(e.message || 'Test send failed', 'error');
    } finally {
      sending = false;
    }
  }

  async function broadcast() {
    sending = true;
    confirmingSend = false;
    try {
      const res = await nlApi.send({ subject, html: contentHtml });
      addToast(`Sent to ${res.sent} of ${res.recipients} recipients`, 'success');
      subject = '';
      contentHtml = '';
    } catch (e) {
      addToast(e.message || 'Send failed', 'error');
    } finally {
      sending = false;
    }
  }

  let canSend = $derived(subject.trim() !== '' && contentHtml.trim() !== '' && !sending);
</script>

<div class="page">
  <div class="page-header">
    <div class="page-header-text">
      <h1>Newsletter</h1>
      <p>Compose and send updates to your subscribers.</p>
    </div>
  </div>

  <div class="stats-row">
    <div class="stat"><span class="stat-num">{counts.confirmed}</span><span class="stat-label">Confirmed</span></div>
    <div class="stat"><span class="stat-num">{counts.pending}</span><span class="stat-label">Pending</span></div>
    <div class="stat"><span class="stat-num">{memberOptins}</span><span class="stat-label">Members opted in</span></div>
    <div class="stat highlight"><span class="stat-num">{totalRecipients}</span><span class="stat-label">Total recipients</span></div>
  </div>

  <div class="compose">
    <label class="field">
      <span>Subject</span>
      <input type="text" bind:value={subject} placeholder="What's this update about?" />
    </label>
    <label class="field">
      <span>Content</span>
      <RichTextEditor content={contentHtml} onupdate={(html) => (contentHtml = html)} placeholder="Write your newsletter…" />
    </label>

    <div class="send-row">
      <div class="test">
        <input type="email" bind:value={testEmail} placeholder="you@example.com" aria-label="Test email address" />
        <button class="btn btn-secondary" onclick={sendTest} disabled={sending}>Send test</button>
      </div>
      {#if confirmingSend}
        <div class="confirm">
          <span>Send to {totalRecipients} recipient{totalRecipients === 1 ? '' : 's'}?</span>
          <button class="btn btn-primary" onclick={broadcast} disabled={sending}>{sending ? 'Sending…' : 'Yes, send'}</button>
          <button class="btn btn-ghost" onclick={() => (confirmingSend = false)}>Cancel</button>
        </div>
      {:else}
        <button class="btn btn-primary" onclick={() => (confirmingSend = true)} disabled={!canSend || totalRecipients === 0}>
          Send to {totalRecipients} subscriber{totalRecipients === 1 ? '' : 's'}
        </button>
      {/if}
    </div>
  </div>

  <div class="subscribers">
    <h2>Subscribers</h2>
    {#if loading}
      <p class="muted">Loading…</p>
    {:else if subscribers.length === 0}
      <p class="muted">No subscribers yet. Add a <code>data-outpost-newsletter</code> signup form to your theme, or opt members in.</p>
    {:else}
      <table>
        <thead><tr><th>Email</th><th>Status</th><th>Source</th><th>Joined</th></tr></thead>
        <tbody>
          {#each subscribers as s (s.id)}
            <tr>
              <td>{s.email}</td>
              <td><span class="badge {s.status}">{s.status}</span></td>
              <td class="muted">{s.source || '—'}</td>
              <td class="muted">{s.created_at?.slice(0, 10) || ''}</td>
            </tr>
          {/each}
        </tbody>
      </table>
    {/if}
  </div>
</div>

<style>
  .page { max-width: var(--content-width, 900px); margin: 0 auto; padding: var(--space-lg); }
  .stats-row { display: flex; gap: 12px; margin-bottom: 28px; flex-wrap: wrap; }
  .stat { flex: 1; min-width: 120px; padding: 16px; border: 1px solid var(--border); border-radius: 10px; display: flex; flex-direction: column; gap: 4px; }
  .stat.highlight { border-color: var(--purple); }
  .stat-num { font-size: 24px; font-weight: 700; color: var(--text); }
  .stat-label { font-size: 12px; color: var(--dim); text-transform: uppercase; letter-spacing: 0.04em; }

  .compose { margin-bottom: 36px; }
  .field { display: flex; flex-direction: column; gap: 6px; margin-bottom: 18px; }
  .field > span { font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; color: var(--dim); }
  .field input { padding: 10px 12px; border: 1px solid var(--border); border-radius: 8px; background: var(--bg); color: var(--text); font-size: 15px; }
  .field input:focus-visible { outline: none; border-color: var(--purple); }

  .send-row { display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; margin-top: 8px; }
  .test { display: flex; gap: 8px; }
  .test input { padding: 8px 11px; border: 1px solid var(--border); border-radius: 8px; background: var(--bg); color: var(--text); font-size: 13px; }
  .confirm { display: flex; align-items: center; gap: 10px; font-size: 13px; color: var(--text); }

  .subscribers h2 { font-size: 15px; margin: 0 0 14px; }
  .subscribers table { width: 100%; border-collapse: collapse; font-size: 13px; }
  .subscribers th { text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: 0.04em; color: var(--dim); padding: 8px 10px; border-bottom: 1px solid var(--border); }
  .subscribers td { padding: 9px 10px; border-bottom: 1px solid var(--border-faint, var(--border)); color: var(--text); }
  .muted { color: var(--dim); font-size: 13px; }
  .badge { padding: 2px 8px; border-radius: 20px; font-size: 11px; font-weight: 600; }
  .badge.confirmed { background: var(--green-bg, #dcfce7); color: var(--green, #16a34a); }
  .badge.pending { background: var(--amber-bg, #fef3c7); color: var(--amber, #d97706); }
  .badge.unsubscribed { background: var(--hover); color: var(--dim); }
</style>
