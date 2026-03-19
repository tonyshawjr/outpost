<script>
  import { onMount } from 'svelte';
  import { reviewTokens as api } from '$lib/api.js';
  import { addToast } from '$lib/stores.js';

  let tokens = $state([]);
  let loading = $state(true);
  let showCreate = $state(false);
  let newName = $state('');
  let newPagePath = $state('');
  let newExpires = $state('');
  let creating = $state(false);

  onMount(async () => {
    await loadTokens();
  });

  async function loadTokens() {
    loading = true;
    try {
      const data = await api.list();
      tokens = data.tokens || [];
    } catch (e) {
      addToast(e.message, 'error');
    } finally {
      loading = false;
    }
  }

  async function createToken() {
    if (!newName.trim() || creating) return;
    creating = true;
    try {
      await api.create({
        name: newName.trim(),
        page_path: newPagePath.trim(),
        expires_at: newExpires || null,
      });
      newName = '';
      newPagePath = '';
      newExpires = '';
      showCreate = false;
      await loadTokens();
      addToast('Review link created', 'success');
    } catch (e) {
      addToast(e.message, 'error');
    } finally {
      creating = false;
    }
  }

  async function toggleToken(token) {
    try {
      await api.toggle(token.id);
      await loadTokens();
      addToast(token.active ? 'Link deactivated' : 'Link activated', 'success');
    } catch (e) {
      addToast(e.message, 'error');
    }
  }

  async function deleteToken(token) {
    if (!confirm('Delete this review link? Existing feedback will remain.')) return;
    try {
      await api.delete(token.id);
      await loadTokens();
      addToast('Review link deleted', 'success');
    } catch (e) {
      addToast(e.message, 'error');
    }
  }

  function getReviewUrl(token) {
    const base = window.location.origin;
    const path = token.page_path || '/';
    return base + path + '?review=' + token.token;
  }

  function copyUrl(token) {
    navigator.clipboard.writeText(getReviewUrl(token));
    addToast('Link copied to clipboard', 'success');
  }

  function formatDate(dateStr) {
    if (!dateStr) return '';
    return new Date(dateStr + (dateStr.includes('Z') ? '' : 'Z')).toLocaleDateString('en-US', {
      month: 'short', day: 'numeric', year: 'numeric'
    });
  }

  function isExpired(token) {
    if (!token.expires_at) return false;
    return new Date(token.expires_at + 'Z') < new Date();
  }
</script>

<div class="review-tokens-page">
  <div class="page-header">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="page-icon"><path d="M10 13a5 5 0 007.54.54l3-3a5 5 0 00-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 00-7.54-.54l-3 3a5 5 0 007.07 7.07l1.71-1.71"/></svg>
    <div>
      <h1 class="page-title">Review Links</h1>
      <p class="page-subtitle">Share links with clients to collect feedback on your site</p>
    </div>
    <div class="page-header-actions">
      <button class="btn btn-primary" onclick={() => showCreate = true}>
        + New Review Link
      </button>
    </div>
  </div>

  {#if showCreate}
    <div class="create-form">
      <div class="create-form-inner">
        <div class="form-field">
          <label class="field-label">NAME</label>
          <input
            type="text"
            class="field-input"
            placeholder="e.g., Client Review — Homepage"
            bind:value={newName}
            onkeydown={(e) => { if (e.key === 'Enter') createToken(); }}
          />
        </div>
        <div class="form-row">
          <div class="form-field">
            <label class="field-label">PAGE (OPTIONAL)</label>
            <input
              type="text"
              class="field-input"
              placeholder="e.g., /about — leave empty for whole site"
              bind:value={newPagePath}
            />
          </div>
          <div class="form-field">
            <label class="field-label">EXPIRES (OPTIONAL)</label>
            <input
              type="datetime-local"
              class="field-input"
              bind:value={newExpires}
            />
          </div>
        </div>
        <div class="form-actions">
          <button class="btn btn-primary" onclick={createToken} disabled={creating || !newName.trim()}>
            {creating ? 'Creating...' : 'Create Link'}
          </button>
          <button class="btn btn-secondary" onclick={() => showCreate = false}>Cancel</button>
        </div>
      </div>
    </div>
  {/if}

  {#if loading}
    <div class="loading-state">Loading...</div>
  {:else if tokens.length === 0}
    <div class="empty-state">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="40" height="40" style="opacity:0.3;margin-bottom:12px"><path d="M10 13a5 5 0 007.54.54l3-3a5 5 0 00-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 00-7.54-.54l-3 3a5 5 0 007.07 7.07l1.71-1.71"/></svg>
      <p>No review links yet</p>
      <p class="empty-hint">Create a shareable link so clients can leave feedback directly on your site without needing an account.</p>
    </div>
  {:else}
    <div class="tokens-list">
      {#each tokens as token (token.id)}
        <div class="token-card" class:inactive={!token.active || isExpired(token)}>
          <div class="token-info">
            <div class="token-name">{token.name}</div>
            <div class="token-meta">
              {#if token.page_path}
                <span class="token-scope">{token.page_path}</span>
              {:else}
                <span class="token-scope">Whole site</span>
              {/if}
              <span class="token-separator">&middot;</span>
              <span>Created {formatDate(token.created_at)}</span>
              {#if token.created_by_username}
                <span class="token-separator">&middot;</span>
                <span>by {token.created_by_username}</span>
              {/if}
              {#if token.expires_at}
                <span class="token-separator">&middot;</span>
                {#if isExpired(token)}
                  <span class="token-expired">Expired</span>
                {:else}
                  <span>Expires {formatDate(token.expires_at)}</span>
                {/if}
              {/if}
            </div>
            <div class="token-url">{getReviewUrl(token)}</div>
          </div>
          <div class="token-actions">
            <button class="btn btn-secondary btn-sm" onclick={() => copyUrl(token)} title="Copy link">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="14" height="14"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>
              Copy
            </button>
            <button
              class="btn btn-secondary btn-sm"
              onclick={() => toggleToken(token)}
              title={token.active ? 'Deactivate' : 'Activate'}
            >
              {token.active ? 'Deactivate' : 'Activate'}
            </button>
            <button class="btn btn-secondary btn-sm token-delete" onclick={() => deleteToken(token)} title="Delete">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="14" height="14"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/></svg>
            </button>
          </div>
        </div>
      {/each}
    </div>
  {/if}
</div>

<style>
  .review-tokens-page {
    max-width: var(--content-width);
    margin: 0 auto;
    padding: 0 24px 80px;
  }

  .create-form {
    margin-bottom: 24px;
    border: 1px solid var(--border);
    border-radius: var(--radius-lg, 8px);
    background: var(--bg-primary);
  }

  .create-form-inner {
    padding: 20px;
  }

  .form-field {
    margin-bottom: 12px;
  }

  .form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
  }

  .field-label {
    display: block;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--text-muted);
    margin-bottom: 4px;
  }

  .field-input {
    width: 100%;
    padding: 8px 10px;
    border: 1px solid var(--border);
    border-radius: 6px;
    font-size: 14px;
    color: var(--text-primary);
    background: var(--bg-primary);
    box-sizing: border-box;
  }

  .field-input:focus {
    outline: none;
    border-color: var(--accent, #3b82f6);
  }

  .form-actions {
    display: flex;
    gap: 8px;
    margin-top: 16px;
  }

  .tokens-list {
    display: flex;
    flex-direction: column;
    gap: 1px;
  }

  .token-card {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
    padding: 16px 0;
    border-bottom: 1px solid var(--border);
  }

  .token-card.inactive { opacity: 0.5; }

  .token-info {
    flex: 1;
    min-width: 0;
  }

  .token-name {
    font-size: 15px;
    font-weight: 600;
    color: var(--text-primary);
    margin-bottom: 4px;
  }

  .token-meta {
    font-size: 13px;
    color: var(--text-secondary);
    margin-bottom: 4px;
  }

  .token-separator {
    margin: 0 4px;
    color: var(--text-muted);
  }

  .token-scope {
    font-family: monospace;
    font-size: 12px;
  }

  .token-expired {
    color: #ef4444;
    font-weight: 500;
  }

  .token-url {
    font-size: 12px;
    color: var(--text-muted);
    font-family: monospace;
    word-break: break-all;
  }

  .token-actions {
    display: flex;
    gap: 6px;
    flex-shrink: 0;
  }

  .btn-sm {
    padding: 4px 10px;
    font-size: 12px;
    display: flex;
    align-items: center;
    gap: 4px;
  }

  .token-delete:hover {
    color: #ef4444;
    border-color: #ef4444;
  }

  .loading-state,
  .empty-state {
    text-align: center;
    padding: 60px 20px;
    color: var(--text-muted);
    font-size: 14px;
  }

  .empty-hint {
    margin-top: 8px;
    font-size: 13px;
    max-width: 400px;
    margin-left: auto;
    margin-right: auto;
  }

  @media (max-width: 640px) {
    .form-row { grid-template-columns: 1fr; }
    .token-card { flex-direction: column; }
    .token-actions { margin-top: 8px; }
  }
</style>
