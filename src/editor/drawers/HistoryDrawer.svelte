<script>
  import { request } from '../api.js';

  let {
    pageId = null,
    apiUrl = '/outpost/api.php',
    csrfToken = '',
  } = $props();

  let revisions = $state([]);
  let loading = $state(true);
  let expandedId = $state(null);
  let diffData = $state(null);
  let diffLoading = $state(false);
  let confirmId = $state(null);
  let restoring = $state(null);

  $effect(() => {
    if (pageId) loadRevisions();
  });

  async function loadRevisions() {
    loading = true;
    try {
      const data = await request('revisions', {
        params: { entity_type: 'page', entity_id: String(pageId) },
      });
      revisions = data.revisions || [];
    } catch (err) {
      console.error('[OPE] Failed to load revisions:', err);
      revisions = [];
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
      const data = await request('revisions/diff', {
        params: {
          entity_type: 'page',
          entity_id: String(pageId),
          revision_id: String(revId),
        },
      });
      diffData = data.changes || [];
    } catch {
      diffData = [];
    } finally {
      diffLoading = false;
    }
  }

  async function restore(revId) {
    restoring = revId;
    try {
      await request('revisions/restore', {
        method: 'POST',
        body: {
          entity_type: 'page',
          entity_id: pageId,
          revision_id: revId,
        },
      });
      confirmId = null;
      expandedId = null;
      diffData = null;
      await loadRevisions();
      window.location.reload();
    } catch (err) {
      console.error('[OPE] Restore failed:', err);
    } finally {
      restoring = null;
    }
  }

  function timeAgo(dateStr) {
    if (!dateStr) return '';
    const date = new Date(dateStr + (dateStr.includes('Z') || dateStr.includes('T') ? '' : 'Z'));
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
    return date.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
  }

  function fieldLabel(name) {
    return (name || '').replace(/_/g, ' ');
  }
</script>

<div class="ope-history-drawer">
  {#if loading}
    <div class="ope-history-empty">Loading revisions...</div>
  {:else if revisions.length === 0}
    <div class="ope-history-empty">
      <svg class="ope-history-empty-icon" viewBox="0 0 24 24" fill="none" stroke="#D1D5DB" stroke-width="1.5">
        <circle cx="12" cy="12" r="10"/>
        <polyline points="12 6 12 12 16 14"/>
      </svg>
      <p>No revisions yet</p>
      <p class="ope-history-empty-sub">Revisions are created each time you save.</p>
    </div>
  {:else}
    <div class="ope-history-list">
      {#each revisions as rev (rev.id)}
        <div class="ope-history-item" class:ope-history-item-expanded={expandedId === rev.id}>
          <div class="ope-history-row">
            <button class="ope-history-info" onclick={() => toggleDiff(rev.id)}>
              <div class="ope-history-dot"></div>
              <div class="ope-history-meta">
                <span class="ope-history-time">{timeAgo(rev.created_at)}</span>
                {#if rev.user}
                  <span class="ope-history-user">{rev.user}</span>
                {/if}
              </div>
              <span class="ope-history-fields">{rev.field_count} field{rev.field_count !== 1 ? 's' : ''}</span>
              <svg
                class="ope-history-chevron"
                class:ope-history-chevron-open={expandedId === rev.id}
                width="12" height="12" viewBox="0 0 12 12" fill="none"
              >
                <path d="M3 4.5l3 3 3-3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
              </svg>
            </button>

            {#if confirmId === rev.id}
              <div class="ope-history-confirm">
                <span class="ope-history-confirm-text">Restore this version?</span>
                <div class="ope-history-confirm-actions">
                  <button class="ope-history-btn ope-history-btn-restore" onclick={() => restore(rev.id)} disabled={restoring === rev.id}>
                    {restoring === rev.id ? 'Restoring...' : 'Restore'}
                  </button>
                  <button class="ope-history-btn ope-history-btn-cancel" onclick={() => { confirmId = null; }}>Cancel</button>
                </div>
              </div>
            {:else}
              <button class="ope-history-restore-link" onclick={() => { confirmId = rev.id; }}>
                Restore
              </button>
            {/if}
          </div>

          {#if expandedId === rev.id}
            <div class="ope-history-diff">
              {#if diffLoading}
                <div class="ope-history-diff-loading">Loading changes...</div>
              {:else if diffData && diffData.length === 0}
                <div class="ope-history-diff-loading">No field changes detected</div>
              {:else if diffData}
                {#each diffData as change}
                  <div class="ope-history-diff-row">
                    <div class="ope-history-diff-field">{fieldLabel(change.field)}</div>
                    <div class="ope-history-diff-values">
                      {#if change.type === 'richtext' || change.type === 'image' || change.type === 'gallery'}
                        <span class="ope-history-diff-meta">{change.type} changed</span>
                      {:else if !change.old}
                        <span class="ope-history-diff-added">{change.new}</span>
                      {:else if !change.new}
                        <span class="ope-history-diff-removed">{change.old}</span>
                      {:else}
                        <span class="ope-history-diff-removed">{change.old}</span>
                        <svg class="ope-history-diff-arrow" width="12" height="12" viewBox="0 0 16 16" fill="none">
                          <path d="M3 8h10M10 5l3 3-3 3" stroke="#D1D5DB" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                        <span class="ope-history-diff-added">{change.new}</span>
                      {/if}
                    </div>
                  </div>
                {/each}
              {/if}
            </div>
          {/if}
        </div>
      {/each}
    </div>
  {/if}
</div>

<style>
  .ope-history-drawer {
    padding: 0;
    height: 100%;
    overflow-y: auto;
  }

  .ope-history-empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 48px 20px;
    text-align: center;
    color: #9CA3AF;
    font-size: 13px;
    line-height: 1.5;
  }
  .ope-history-empty p { margin: 0 0 4px; }
  .ope-history-empty-icon { width: 32px; height: 32px; margin-bottom: 12px; }
  .ope-history-empty-sub { font-size: 12px; color: #D1D5DB; }

  .ope-history-list { padding: 0; }

  .ope-history-item { border-bottom: 1px solid #F3F4F6; }
  .ope-history-item:last-child { border-bottom: none; }

  .ope-history-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 20px;
    gap: 8px;
  }

  .ope-history-info {
    display: flex;
    align-items: center;
    gap: 10px;
    flex: 1;
    min-width: 0;
    background: none;
    border: none;
    padding: 12px 0;
    cursor: pointer;
    text-align: left;
    font-family: inherit;
  }

  .ope-history-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #E5E7EB;
    flex-shrink: 0;
  }
  .ope-history-item:first-child .ope-history-dot { background: #2D5A47; }

  .ope-history-meta {
    display: flex;
    flex-direction: column;
    gap: 1px;
    min-width: 0;
  }

  .ope-history-time {
    font-size: 13px;
    font-weight: 500;
    color: #111827;
    white-space: nowrap;
  }

  .ope-history-user {
    font-size: 11px;
    color: #9CA3AF;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .ope-history-fields {
    font-size: 11px;
    color: #D1D5DB;
    white-space: nowrap;
    margin-left: auto;
    flex-shrink: 0;
  }

  .ope-history-chevron {
    color: #D1D5DB;
    transition: transform 0.15s;
    flex-shrink: 0;
  }
  .ope-history-chevron-open { transform: rotate(180deg); }
  .ope-history-info:hover .ope-history-time { color: #2D5A47; }

  .ope-history-restore-link {
    background: none;
    border: none;
    font-size: 12px;
    color: #9CA3AF;
    cursor: pointer;
    padding: 4px 0;
    font-family: inherit;
    flex-shrink: 0;
    transition: color 0.15s;
  }
  .ope-history-restore-link:hover { color: #2D5A47; }

  .ope-history-confirm {
    display: flex;
    flex-direction: column;
    gap: 6px;
    padding: 4px 0;
    flex-shrink: 0;
  }
  .ope-history-confirm-text { font-size: 12px; color: #6B7280; }
  .ope-history-confirm-actions { display: flex; gap: 6px; }

  .ope-history-btn {
    border: none;
    cursor: pointer;
    font-size: 12px;
    padding: 4px 10px;
    border-radius: 4px;
    font-family: inherit;
    transition: all 0.15s;
  }
  .ope-history-btn:disabled { opacity: 0.5; cursor: default; }
  .ope-history-btn-restore { background: #2D5A47; color: white; }
  .ope-history-btn-restore:hover:not(:disabled) { background: #1E4535; }
  .ope-history-btn-cancel { background: none; color: #9CA3AF; }
  .ope-history-btn-cancel:hover { color: #374151; }

  .ope-history-diff {
    padding: 0 20px 12px 38px;
    display: flex;
    flex-direction: column;
    gap: 6px;
  }
  .ope-history-diff-loading { font-size: 12px; color: #9CA3AF; padding: 4px 0; }

  .ope-history-diff-row {
    display: flex;
    flex-direction: column;
    gap: 2px;
    padding: 6px 10px;
    background: #F9FAFB;
    border-radius: 6px;
  }

  .ope-history-diff-field {
    font-size: 11px;
    font-weight: 600;
    text-transform: capitalize;
    color: #6B7280;
  }

  .ope-history-diff-values {
    display: flex;
    align-items: center;
    gap: 4px;
    flex-wrap: wrap;
    font-size: 12px;
  }

  .ope-history-diff-removed { text-decoration: line-through; color: #9CA3AF; }
  .ope-history-diff-added { font-weight: 500; color: #111827; }
  .ope-history-diff-meta { font-style: italic; color: #D1D5DB; }
  .ope-history-diff-arrow { flex-shrink: 0; }
</style>
