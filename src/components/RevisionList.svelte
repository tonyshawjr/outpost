<script>
  import { revisions as revisionsApi } from '$lib/api.js';
  import { addToast } from '$lib/stores.js';

  let { entityType, entityId, key = 0, onRestore = () => {} } = $props();

  let revisions = $state([]);
  let loading = $state(true);
  let restoring = $state(null);
  let confirmId = $state(null);
  let expandedId = $state(null);
  let diffData = $state(null);
  let diffLoading = $state(false);

  async function load() {
    if (!entityType || !entityId) return;
    loading = true;
    try {
      const res = await revisionsApi.list(entityType, entityId);
      revisions = res.revisions || [];
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      loading = false;
    }
  }

  async function toggleDiff(revId) {
    if (expandedId === revId) {
      expandedId = null;
      diffData = null;
      return;
    }
    expandedId = revId;
    diffData = null;
    diffLoading = true;
    try {
      const res = await revisionsApi.diff(entityType, entityId, revId);
      diffData = res.changes || [];
    } catch (err) {
      diffData = [];
      addToast(err.message, 'error');
    } finally {
      diffLoading = false;
    }
  }

  async function restore(revId) {
    restoring = revId;
    try {
      await revisionsApi.restore(entityType, entityId, revId);
      addToast('Revision restored', 'success');
      confirmId = null;
      expandedId = null;
      diffData = null;
      await load();
      onRestore();
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      restoring = null;
    }
  }

  function timeAgo(dateStr) {
    const date = new Date(dateStr + 'Z');
    const now = new Date();
    const seconds = Math.floor((now - date) / 1000);
    if (seconds < 60) return 'Just now';
    const minutes = Math.floor(seconds / 60);
    if (minutes < 60) return `${minutes}m ago`;
    const hours = Math.floor(minutes / 60);
    if (hours < 24) return `${hours}h ago`;
    const days = Math.floor(hours / 24);
    if (days === 1) return 'Yesterday';
    if (days < 30) return `${days}d ago`;
    return date.toLocaleDateString();
  }

  function fieldLabel(name) {
    return name.replace(/_/g, ' ');
  }

  $effect(() => {
    entityType;
    entityId;
    key;
    load();
  });
</script>

<div class="rev-list">
  {#if loading}
    <div class="rev-empty">Loading...</div>
  {:else if revisions.length === 0}
    <div class="rev-empty">No revisions yet. Revisions are created automatically each time you save.</div>
  {:else}
    {#each revisions as rev (rev.id)}
      <div class="rev-row" class:rev-expanded={expandedId === rev.id}>
        <div class="rev-item" class:rev-confirming={confirmId === rev.id}>
          <button class="rev-info" onclick={() => toggleDiff(rev.id)}>
            <span class="rev-time">{timeAgo(rev.created_at)}</span>
            {#if rev.user}
              <span class="rev-user">{rev.user}</span>
            {/if}
            <span class="rev-fields">{rev.field_count} field{rev.field_count !== 1 ? 's' : ''}</span>
            <span class="rev-chevron" class:rev-chevron-open={expandedId === rev.id}></span>
          </button>
          {#if confirmId === rev.id}
            <div class="rev-confirm">
              <span class="rev-confirm-text">Restore this version? Current content will be saved first.</span>
              <div class="rev-confirm-actions">
                <button class="rev-btn rev-btn-restore" onclick={() => restore(rev.id)} disabled={restoring === rev.id}>
                  {restoring === rev.id ? 'Restoring...' : 'Restore'}
                </button>
                <button class="rev-btn rev-btn-cancel" onclick={() => confirmId = null}>Cancel</button>
              </div>
            </div>
          {:else}
            <button class="rev-btn rev-btn-outline" onclick={() => confirmId = rev.id}>Restore</button>
          {/if}
        </div>

        {#if expandedId === rev.id}
          <div class="rev-diff">
            {#if diffLoading}
              <div class="rev-diff-loading">Loading changes...</div>
            {:else if diffData && diffData.length === 0}
              <div class="rev-diff-empty">No field changes detected</div>
            {:else if diffData}
              <table class="rev-diff-table">
                <thead>
                  <tr>
                    <th>Field</th>
                    <th>Change</th>
                  </tr>
                </thead>
                <tbody>
                  {#each diffData as change}
                    <tr>
                      <td class="rev-diff-field">{fieldLabel(change.field)}</td>
                      <td class="rev-diff-change">
                        {#if change.type === 'richtext'}
                          <span class="rev-diff-meta">content changed</span>
                        {:else if change.type === 'image' || change.type === 'gallery'}
                          <span class="rev-diff-meta">{change.type} changed</span>
                        {:else if !change.old}
                          <span class="rev-diff-added">{change.new}</span>
                        {:else if !change.new}
                          <span class="rev-diff-removed">{change.old}</span>
                        {:else}
                          <span class="rev-diff-removed">{change.old}</span>
                          <span class="rev-diff-arrow">&rarr;</span>
                          <span class="rev-diff-added">{change.new}</span>
                        {/if}
                      </td>
                    </tr>
                  {/each}
                </tbody>
              </table>
            {/if}
          </div>
        {/if}
      </div>
    {/each}
  {/if}
</div>

<style>
  .rev-list {
    display: flex;
    flex-direction: column;
    gap: 0;
  }
  .rev-empty {
    padding: 24px 0;
    color: var(--text-muted, #999);
    font-size: 13px;
    line-height: 1.5;
  }
  .rev-row {
    border-bottom: 1px solid var(--border-light, #f0f0f0);
  }
  .rev-row:last-child {
    border-bottom: none;
  }
  .rev-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 10px 0;
    gap: 8px;
  }
  .rev-confirming {
    flex-direction: column;
    align-items: stretch;
  }
  .rev-info {
    display: flex;
    align-items: center;
    gap: 8px;
    flex: 1;
    min-width: 0;
    background: none;
    border: none;
    padding: 0;
    cursor: pointer;
    text-align: left;
    font-family: inherit;
  }
  .rev-info:hover .rev-time {
    color: var(--accent, #111);
  }
  .rev-time {
    font-size: 13px;
    color: var(--text-primary, #111);
    font-weight: 500;
    white-space: nowrap;
    transition: color 0.15s;
  }
  .rev-user {
    font-size: 11px;
    color: var(--text-muted, #999);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }
  .rev-fields {
    font-size: 11px;
    color: var(--text-muted, #bbb);
    white-space: nowrap;
    margin-left: auto;
  }
  .rev-chevron {
    width: 0;
    height: 0;
    border-left: 4px solid transparent;
    border-right: 4px solid transparent;
    border-top: 5px solid var(--text-muted, #ccc);
    transition: transform 0.15s;
    flex-shrink: 0;
  }
  .rev-chevron-open {
    transform: rotate(180deg);
  }
  .rev-btn {
    border: none;
    cursor: pointer;
    font-size: 12px;
    padding: 4px 10px;
    border-radius: 4px;
    white-space: nowrap;
    transition: background 0.15s;
  }
  .rev-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
  }
  .rev-btn-outline {
    background: none;
    color: var(--text-muted, #999);
  }
  .rev-btn-outline:hover {
    color: var(--text-primary, #111);
    background: var(--bg-hover, #f5f5f5);
  }
  .rev-btn-restore {
    background: var(--accent, #111);
    color: #fff;
    padding: 5px 14px;
  }
  .rev-btn-restore:hover {
    opacity: 0.85;
  }
  .rev-btn-cancel {
    background: none;
    color: var(--text-muted, #999);
  }
  .rev-btn-cancel:hover {
    color: var(--text-primary, #111);
  }
  .rev-confirm {
    margin-top: 6px;
  }
  .rev-confirm-text {
    font-size: 12px;
    color: var(--text-muted, #777);
    display: block;
    margin-bottom: 8px;
    line-height: 1.4;
  }
  .rev-confirm-actions {
    display: flex;
    gap: 8px;
  }

  /* Diff panel */
  .rev-diff {
    padding: 0 0 12px;
  }
  .rev-diff-loading,
  .rev-diff-empty {
    font-size: 12px;
    color: var(--text-muted, #999);
    padding: 8px 0;
  }
  .rev-diff-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 12px;
  }
  .rev-diff-table th {
    text-align: left;
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--text-muted, #aaa);
    font-weight: 500;
    padding: 4px 8px 6px 0;
    border-bottom: 1px solid var(--border-light, #f0f0f0);
  }
  .rev-diff-table td {
    padding: 6px 8px 6px 0;
    vertical-align: top;
    border-bottom: 1px solid var(--border-light, #f5f5f5);
  }
  .rev-diff-table tr:last-child td {
    border-bottom: none;
  }
  .rev-diff-field {
    color: var(--text-primary, #333);
    font-weight: 500;
    white-space: nowrap;
    width: 1%;
  }
  .rev-diff-change {
    color: var(--text-secondary, #555);
    word-break: break-word;
  }
  .rev-diff-meta {
    font-style: italic;
    color: var(--text-muted, #aaa);
  }
  .rev-diff-removed {
    text-decoration: line-through;
    color: var(--text-muted, #aaa);
  }
  .rev-diff-added {
    font-weight: 500;
    color: var(--text-primary, #222);
  }
  .rev-diff-arrow {
    margin: 0 4px;
    color: var(--text-muted, #ccc);
  }
</style>
