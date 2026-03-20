<script>
  import { onMount } from 'svelte';
  import { reviewTokens as api, comments as commentsApi } from '$lib/api.js';
  import { addToast } from '$lib/stores.js';

  let tokens = $state([]);
  let tokenCommentCounts = $state({});
  let loading = $state(true);
  let showCreate = $state(false);
  let selectedToken = $state(null);
  let feedbackComments = $state([]);
  let loadingFeedback = $state(false);
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
      // Load comment counts per token
      await loadTokenCommentCounts();
    } catch (e) {
      addToast(e.message, 'error');
    } finally {
      loading = false;
    }
  }

  async function loadTokenCommentCounts() {
    const counts = {};
    for (const token of tokens) {
      try {
        const data = await commentsApi.list({ review_token_id: token.id });
        const comments = data.comments || [];
        const openCount = comments.filter(c => c.status === 'open').length;
        if (openCount > 0) counts[token.id] = openCount;
      } catch (e) {
        // ignore
      }
    }
    tokenCommentCounts = counts;
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

  function getAdminReviewUrl(token, comment) {
    const base = window.location.origin;
    const path = comment?.page_path || token.page_path || '/';
    let url = base + path + '?review=' + token.token + '&admin=1';
    if (comment?.id) url += '#comment-' + comment.id;
    return url;
  }

  function viewOnPage(token, comment) {
    window.open(getAdminReviewUrl(token, comment), '_blank');
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

  let feedbackFilter = $state('all');

  let filteredFeedback = $derived(
    feedbackFilter === 'all' ? feedbackComments :
    feedbackComments.filter(c => c.status === feedbackFilter)
  );

  let feedbackOpenCount = $derived(feedbackComments.filter(c => c.status === 'open').length);
  let feedbackResolvedCount = $derived(feedbackComments.filter(c => c.status === 'resolved').length);

  async function viewFeedback(token) {
    selectedToken = token;
    loadingFeedback = true;
    feedbackFilter = 'all';
    try {
      const data = await commentsApi.list({ review_token_id: token.id });
      feedbackComments = data.comments || [];
    } catch (e) {
      feedbackComments = [];
      addToast('Failed to load feedback', 'error');
    } finally {
      loadingFeedback = false;
    }
  }

  function closeFeedback() {
    selectedToken = null;
    feedbackComments = [];
    feedbackFilter = 'all';
  }

  async function resolveComment(comment) {
    const newStatus = comment.status === 'open' ? 'resolved' : 'open';
    try {
      await commentsApi.update(comment.id, { status: newStatus });
      await viewFeedback(selectedToken);
      addToast(newStatus === 'resolved' ? 'Comment resolved' : 'Comment reopened', 'success');
    } catch (e) {
      addToast(e.message, 'error');
    }
  }

  async function deleteFeedbackComment(id) {
    if (!confirm('Delete this comment?')) return;
    try {
      await commentsApi.delete(id);
      await viewFeedback(selectedToken);
      addToast('Comment deleted', 'success');
    } catch (e) {
      addToast(e.message, 'error');
    }
  }

  function formatTime(dateStr) {
    if (!dateStr) return '';
    const d = new Date(dateStr + (dateStr.includes('Z') ? '' : 'Z'));
    const now = new Date();
    const diff = now - d;
    if (diff < 60000) return 'Just now';
    if (diff < 3600000) return `${Math.floor(diff / 60000)}m ago`;
    if (diff < 86400000) return `${Math.floor(diff / 3600000)}h ago`;
    return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
  }

  function getInitials(name) {
    return (name || '?').split(/\s+/).map(w => w[0]).join('').toUpperCase().substring(0, 2);
  }

  function describeSelector(selector) {
    if (!selector) return 'General comment';
    // Extract meaningful part from CSS selector
    const parts = selector.split(' > ');
    const last = parts[parts.length - 1] || '';
    if (last.startsWith('#')) return last;
    // Try to make it human-readable
    const tag = last.replace(/:nth-of-type\(\d+\)/, '');
    const tagMap = {
      nav: 'Navigation', header: 'Header', footer: 'Footer',
      main: 'Main content', section: 'Section', article: 'Article',
      h1: 'Heading', h2: 'Heading', h3: 'Heading', p: 'Paragraph',
      img: 'Image', a: 'Link', button: 'Button', div: 'Container',
      ul: 'List', form: 'Form', aside: 'Sidebar',
    };
    return tagMap[tag] || 'Page element';
  }
</script>

<div class="review-tokens-page">
  <div class="page-header">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="24" height="24" style="flex-shrink:0"><path d="M10 13a5 5 0 007.54.54l3-3a5 5 0 00-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 00-7.54-.54l-3 3a5 5 0 007.07 7.07l1.71-1.71"/></svg>
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
            {#if tokenCommentCounts[token.id]}
              <span class="token-comment-count" title="{tokenCommentCounts[token.id]} open comment{tokenCommentCounts[token.id] !== 1 ? 's' : ''}">
                <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" width="12" height="12">
                  <path d="M14 10a2 2 0 01-2 2H5l-3 3V4a2 2 0 012-2h8a2 2 0 012 2z"/>
                </svg>
                {tokenCommentCounts[token.id]} open
              </span>
            {/if}
          </div>
          <div class="token-actions">
            <button class="btn btn-secondary btn-sm" onclick={() => viewFeedback(token)} title="View feedback">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="14" height="14"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
              Feedback
            </button>
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

  <!-- Feedback Inbox Panel -->
  {#if selectedToken}
    <div class="feedback-overlay">
      <div class="feedback-panel">
        <div class="feedback-header">
          <div class="feedback-header-info">
            <button class="feedback-back" onclick={closeFeedback} title="Back to review links">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="18" height="18"><polyline points="15 18 9 12 15 6"/></svg>
            </button>
            <div>
              <h2 class="feedback-title">{selectedToken.name}</h2>
              <p class="feedback-subtitle">
                {selectedToken.page_path || 'Whole site'}
                {#if feedbackComments.length > 0}
                  <span class="feedback-separator">&middot;</span>
                  {feedbackComments.length} comment{feedbackComments.length !== 1 ? 's' : ''}
                {/if}
              </p>
            </div>
          </div>
          <button class="btn btn-primary btn-sm feedback-view-page" onclick={() => viewOnPage(selectedToken, null)} title="Open site with admin review overlay">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="14" height="14"><path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
            View on page
          </button>
          <button class="feedback-close" onclick={closeFeedback} title="Close">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="18" height="18"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
          </button>
        </div>

        <!-- Filter tabs -->
        <div class="feedback-filters">
          <button class="feedback-filter-btn" class:active={feedbackFilter === 'all'} onclick={() => feedbackFilter = 'all'}>
            All <span class="feedback-count">{feedbackComments.length}</span>
          </button>
          <button class="feedback-filter-btn" class:active={feedbackFilter === 'open'} onclick={() => feedbackFilter = 'open'}>
            Open <span class="feedback-count">{feedbackOpenCount}</span>
          </button>
          <button class="feedback-filter-btn" class:active={feedbackFilter === 'resolved'} onclick={() => feedbackFilter = 'resolved'}>
            Resolved <span class="feedback-count">{feedbackResolvedCount}</span>
          </button>
        </div>

        <div class="feedback-body">
          {#if loadingFeedback}
            <div class="feedback-empty">Loading feedback...</div>
          {:else if filteredFeedback.length === 0}
            <div class="feedback-empty">
              {#if feedbackFilter === 'all'}
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="32" height="32" style="opacity:0.25;margin-bottom:8px"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
                <p>No feedback received yet</p>
                <p class="feedback-empty-hint">Share the review link with your client to start collecting feedback.</p>
              {:else}
                <p>No {feedbackFilter} comments.</p>
              {/if}
            </div>
          {:else}
            {#each filteredFeedback as comment (comment.id)}
              <div class="feedback-comment" class:resolved={comment.status === 'resolved'}>
                <div class="feedback-comment-main">
                  <div class="feedback-avatar">{getInitials(comment.author_name)}</div>
                  <div class="feedback-comment-content">
                    <div class="feedback-comment-header">
                      <span class="feedback-author">{comment.author_name || 'Anonymous'}</span>
                      {#if comment.author_email}
                        <span class="feedback-email">{comment.author_email}</span>
                      {/if}
                      <span class="feedback-time">{formatTime(comment.created_at)}</span>
                    </div>

                    {#if comment.page_path || comment.element_selector}
                      <div class="feedback-context">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="12" height="12"><path d="M15 3h6v6M9 21H3v-6M21 3l-7 7M3 21l7-7"/></svg>
                        {#if comment.page_path}
                          <span class="feedback-page-path">{comment.page_path}</span>
                        {/if}
                        {#if comment.element_selector}
                          <span class="feedback-element">{describeSelector(comment.element_selector)}</span>
                        {/if}
                      </div>
                    {/if}

                    <div class="feedback-comment-body">{comment.body}</div>

                    <div class="feedback-comment-actions">
                      <button class="feedback-action-btn" onclick={() => resolveComment(comment)}>
                        {#if comment.status === 'resolved'}
                          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><path d="M3 12l2-2m0 0l7 7 7-7M5 10V4a1 1 0 011-1h5"/></svg>
                          Reopen
                        {:else}
                          <svg viewBox="0 0 24 24" fill="none" stroke="#22c55e" stroke-width="2" width="14" height="14"><polyline points="20 6 9 17 4 12"/></svg>
                          Resolve
                        {/if}
                      </button>
                      <button class="feedback-action-btn feedback-action-delete" onclick={() => deleteFeedbackComment(comment.id)}>
                        Delete
                      </button>
                    </div>
                  </div>
                </div>

                <!-- Replies -->
                {#if comment.replies?.length}
                  <div class="feedback-replies">
                    {#each comment.replies as reply (reply.id)}
                      <div class="feedback-reply">
                        <div class="feedback-avatar feedback-avatar-sm">{getInitials(reply.author_name)}</div>
                        <div class="feedback-comment-content">
                          <div class="feedback-comment-header">
                            <span class="feedback-author">{reply.author_name || 'Anonymous'}</span>
                            <span class="feedback-time">{formatTime(reply.created_at)}</span>
                          </div>
                          <div class="feedback-comment-body">{reply.body}</div>
                        </div>
                      </div>
                    {/each}
                  </div>
                {/if}
              </div>
            {/each}
          {/if}
        </div>
      </div>
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

  .token-comment-count {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 12px;
    color: var(--text-secondary);
    margin-top: 4px;
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

  /* ─── Feedback Inbox ─── */
  .feedback-overlay {
    position: fixed;
    inset: 0;
    z-index: 100;
    background: rgba(0, 0, 0, 0.4);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 24px;
  }

  .feedback-panel {
    background: var(--bg-primary);
    border-radius: 12px;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.2);
    width: 640px;
    max-width: 100%;
    max-height: 80vh;
    display: flex;
    flex-direction: column;
    overflow: hidden;
  }

  .feedback-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 16px 20px;
    border-bottom: 1px solid var(--border);
  }

  .feedback-header-info {
    display: flex;
    align-items: center;
    gap: 12px;
    flex: 1;
    min-width: 0;
  }

  .feedback-back {
    background: none;
    border: none;
    cursor: pointer;
    color: var(--text-muted);
    padding: 4px;
    border-radius: 4px;
    flex-shrink: 0;
  }

  .feedback-back:hover {
    color: var(--text-primary);
    background: var(--bg-secondary);
  }

  .feedback-title {
    font-size: 16px;
    font-weight: 600;
    color: var(--text-primary);
    margin: 0;
    line-height: 1.3;
  }

  .feedback-subtitle {
    font-size: 13px;
    color: var(--text-secondary);
    margin: 2px 0 0;
  }

  .feedback-separator {
    margin: 0 4px;
    color: var(--text-muted);
  }

  .feedback-close {
    background: none;
    border: none;
    cursor: pointer;
    color: var(--text-muted);
    padding: 4px;
    border-radius: 4px;
    flex-shrink: 0;
  }

  .feedback-close:hover {
    color: var(--text-primary);
    background: var(--bg-secondary);
  }

  .feedback-filters {
    display: flex;
    gap: 2px;
    padding: 8px 20px;
    border-bottom: 1px solid var(--border);
  }

  .feedback-filter-btn {
    display: flex;
    align-items: center;
    gap: 4px;
    padding: 4px 10px;
    background: none;
    border: none;
    font-size: 13px;
    color: var(--text-secondary);
    cursor: pointer;
    border-radius: 4px;
    transition: color 0.1s, background 0.1s;
  }

  .feedback-filter-btn:hover {
    color: var(--text-primary);
    background: var(--bg-secondary);
  }

  .feedback-filter-btn.active {
    color: var(--text-primary);
    font-weight: 600;
  }

  .feedback-count {
    font-size: 11px;
    color: var(--text-muted);
    font-variant-numeric: tabular-nums;
  }

  .feedback-body {
    flex: 1;
    overflow-y: auto;
    padding: 0;
  }

  .feedback-empty {
    text-align: center;
    padding: 48px 20px;
    color: var(--text-muted);
    font-size: 14px;
    display: flex;
    flex-direction: column;
    align-items: center;
  }

  .feedback-empty p {
    margin: 0;
  }

  .feedback-empty-hint {
    margin-top: 8px !important;
    font-size: 13px;
    max-width: 320px;
    color: var(--text-muted);
  }

  .feedback-comment {
    padding: 16px 20px;
    border-bottom: 1px solid var(--border);
    transition: opacity 0.15s;
  }

  .feedback-comment:last-child {
    border-bottom: none;
  }

  .feedback-comment.resolved {
    opacity: 0.55;
  }

  .feedback-comment.resolved:hover {
    opacity: 0.85;
  }

  .feedback-comment-main {
    display: flex;
    gap: 12px;
  }

  .feedback-avatar {
    width: 32px;
    height: 32px;
    border-radius: 16px;
    background: var(--bg-tertiary, #e5e7eb);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    font-weight: 600;
    color: var(--text-secondary);
    flex-shrink: 0;
  }

  .feedback-avatar-sm {
    width: 24px;
    height: 24px;
    font-size: 10px;
  }

  .feedback-comment-content {
    flex: 1;
    min-width: 0;
  }

  .feedback-comment-header {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
    margin-bottom: 2px;
  }

  .feedback-author {
    font-size: 13px;
    font-weight: 600;
    color: var(--text-primary);
  }

  .feedback-email {
    font-size: 12px;
    color: var(--text-muted);
  }

  .feedback-time {
    font-size: 12px;
    color: var(--text-muted);
    margin-left: auto;
  }

  .feedback-context {
    display: flex;
    align-items: center;
    gap: 4px;
    font-size: 12px;
    color: var(--text-muted);
    margin-bottom: 4px;
  }

  .feedback-page-path {
    font-family: monospace;
    font-size: 11px;
  }

  .feedback-element {
    font-size: 11px;
  }

  .feedback-comment-body {
    font-size: 14px;
    color: var(--text-primary);
    line-height: 1.55;
    white-space: pre-wrap;
    word-break: break-word;
  }

  .feedback-comment-actions {
    display: flex;
    gap: 2px;
    margin-top: 6px;
  }

  .feedback-action-btn {
    display: flex;
    align-items: center;
    gap: 4px;
    padding: 2px 8px;
    background: none;
    border: none;
    font-size: 12px;
    color: var(--text-muted);
    cursor: pointer;
    border-radius: 4px;
    transition: color 0.1s, background 0.1s;
  }

  .feedback-action-btn:hover {
    color: var(--text-primary);
    background: var(--bg-secondary);
  }

  .feedback-action-delete:hover {
    color: #ef4444;
  }

  .feedback-replies {
    margin-left: 44px;
    border-left: 2px solid var(--border);
    padding-left: 12px;
    margin-top: 8px;
  }

  .feedback-reply {
    display: flex;
    gap: 10px;
    padding: 8px 0;
  }

  @media (max-width: 640px) {
    .form-row { grid-template-columns: 1fr; }
    .token-card { flex-direction: column; }
    .token-actions { margin-top: 8px; flex-wrap: wrap; }
    .feedback-overlay { padding: 0; }
    .feedback-panel { border-radius: 0; max-height: 100vh; height: 100vh; }
  }
</style>
