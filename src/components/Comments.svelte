<script>
  import { onMount } from 'svelte';
  import { comments as commentsApi, users as usersApi } from '$lib/api.js';
  import { user, addToast } from '$lib/stores.js';

  let { entityType = '', entityId = null, pagePath = '' } = $props();

  let commentsList = $state([]);
  let loading = $state(true);
  let newBody = $state('');
  let filter = $state('all');
  let replyTo = $state(null);
  let replyBody = $state('');
  let teamUsers = $state([]);
  let mentionQuery = $state('');
  let showMentionList = $state(false);
  let submitting = $state(false);

  let filtered = $derived(
    filter === 'all' ? commentsList :
    commentsList.filter(c => c.status === filter)
  );

  let openCount = $derived(commentsList.filter(c => c.status === 'open').length);
  let resolvedCount = $derived(commentsList.filter(c => c.status === 'resolved').length);

  onMount(() => {
    loadComments();
    loadUsers();
  });

  // Reload when entity changes
  $effect(() => {
    if (entityType || entityId || pagePath) {
      loadComments();
    }
  });

  async function loadComments() {
    loading = true;
    try {
      const params = {};
      if (entityType) params.entity_type = entityType;
      if (entityId) params.entity_id = entityId;
      if (pagePath) params.page_path = pagePath;
      const data = await commentsApi.list(params);
      commentsList = data.comments || [];
    } catch (e) {
      // ignore
    } finally {
      loading = false;
    }
  }

  async function loadUsers() {
    try {
      const data = await usersApi.list();
      teamUsers = data.users || [];
    } catch (e) {
      // users list may not be accessible for non-admins
    }
  }

  async function submitComment() {
    if (!newBody.trim() || submitting) return;
    submitting = true;
    try {
      await commentsApi.create({
        entity_type: entityType,
        entity_id: entityId,
        page_path: pagePath,
        body: newBody.trim(),
      });
      newBody = '';
      await loadComments();
      addToast('Comment added', 'success');
    } catch (e) {
      addToast(e.message, 'error');
    } finally {
      submitting = false;
    }
  }

  async function submitReply(parentId) {
    if (!replyBody.trim() || submitting) return;
    submitting = true;
    try {
      await commentsApi.create({
        entity_type: entityType,
        entity_id: entityId,
        page_path: pagePath,
        body: replyBody.trim(),
        parent_id: parentId,
      });
      replyBody = '';
      replyTo = null;
      await loadComments();
    } catch (e) {
      addToast(e.message, 'error');
    } finally {
      submitting = false;
    }
  }

  async function toggleResolve(comment) {
    const newStatus = comment.status === 'open' ? 'resolved' : 'open';
    try {
      await commentsApi.update(comment.id, { status: newStatus });
      await loadComments();
      addToast(newStatus === 'resolved' ? 'Comment resolved' : 'Comment reopened', 'success');
    } catch (e) {
      addToast(e.message, 'error');
    }
  }

  async function deleteComment(id) {
    if (!confirm('Delete this comment?')) return;
    try {
      await commentsApi.delete(id);
      await loadComments();
      addToast('Comment deleted', 'success');
    } catch (e) {
      addToast(e.message, 'error');
    }
  }

  function getInitials(comment) {
    if (comment.user?.display_name) {
      return comment.user.display_name.split(/\s+/).map(w => w[0]).join('').toUpperCase().substring(0, 2);
    }
    if (comment.user?.username) return comment.user.username.substring(0, 2).toUpperCase();
    if (comment.author_name) return comment.author_name.split(/\s+/).map(w => w[0]).join('').toUpperCase().substring(0, 2);
    return '?';
  }

  function getDisplayName(comment) {
    return comment.user?.display_name || comment.user?.username || comment.author_name || 'Anonymous';
  }

  function timeAgo(dateStr) {
    const date = new Date(dateStr + (dateStr.includes('Z') ? '' : 'Z'));
    const diff = (Date.now() - date.getTime()) / 1000;
    if (diff < 60) return 'just now';
    if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
    if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
    if (diff < 604800) return Math.floor(diff / 86400) + 'd ago';
    return date.toLocaleDateString();
  }

  function handleKeydown(e) {
    if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) {
      submitComment();
    }
  }

  function insertMention(username) {
    newBody = newBody.replace(/@\w*$/, '@' + username + ' ');
    showMentionList = false;
  }

  function handleInput(e) {
    const value = e.target.value;
    const match = value.match(/@(\w*)$/);
    if (match) {
      mentionQuery = match[1].toLowerCase();
      showMentionList = true;
    } else {
      showMentionList = false;
    }
  }

  let filteredUsers = $derived(
    teamUsers.filter(u =>
      u.username.toLowerCase().includes(mentionQuery) ||
      (u.display_name || '').toLowerCase().includes(mentionQuery)
    ).slice(0, 5)
  );
</script>

<div class="comments-panel">
  <!-- Filter tabs -->
  <div class="comments-filters">
    <button class="comments-filter-btn" class:active={filter === 'all'} onclick={() => filter = 'all'}>
      All <span class="comments-count">{commentsList.length}</span>
    </button>
    <button class="comments-filter-btn" class:active={filter === 'open'} onclick={() => filter = 'open'}>
      Open <span class="comments-count">{openCount}</span>
    </button>
    <button class="comments-filter-btn" class:active={filter === 'resolved'} onclick={() => filter = 'resolved'}>
      Resolved <span class="comments-count">{resolvedCount}</span>
    </button>
  </div>

  <!-- Comment list -->
  <div class="comments-list">
    {#if loading}
      <div class="comments-empty">Loading...</div>
    {:else if filtered.length === 0}
      <div class="comments-empty">
        {#if filter === 'all'}
          No comments yet. Start the conversation.
        {:else}
          No {filter} comments.
        {/if}
      </div>
    {:else}
      {#each filtered as comment (comment.id)}
        <div class="comment-thread" class:resolved={comment.status === 'resolved'}>
          <div class="comment-item">
            <div class="comment-avatar">{getInitials(comment)}</div>
            <div class="comment-content">
              <div class="comment-header">
                <span class="comment-author">{getDisplayName(comment)}</span>
                {#if comment.is_external}
                  <span class="comment-badge-external">External</span>
                {/if}
                <span class="comment-time">{timeAgo(comment.created_at)}</span>
              </div>
              <div class="comment-body">{comment.body}</div>
              <div class="comment-actions">
                <button class="comment-action-btn" onclick={() => toggleResolve(comment)}>
                  {#if comment.status === 'resolved'}
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><path d="M3 12l2-2m0 0l7 7 7-7M5 10V4a1 1 0 011-1h5"/></svg>
                    Reopen
                  {:else}
                    <svg viewBox="0 0 24 24" fill="none" stroke="#22c55e" stroke-width="2" width="14" height="14"><polyline points="20 6 9 17 4 12"/></svg>
                    Resolve
                  {/if}
                </button>
                <button class="comment-action-btn" onclick={() => { replyTo = replyTo === comment.id ? null : comment.id; replyBody = ''; }}>
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><polyline points="9 17 4 12 9 7"/><path d="M20 18v-2a4 4 0 00-4-4H4"/></svg>
                  Reply
                </button>
                {#if comment.user_id === $user?.id || ['super_admin', 'admin'].includes($user?.role)}
                  <button class="comment-action-btn comment-action-delete" onclick={() => deleteComment(comment.id)}>
                    Delete
                  </button>
                {/if}
              </div>
            </div>
          </div>

          <!-- Replies -->
          {#if comment.replies?.length}
            <div class="comment-replies">
              {#each comment.replies as reply (reply.id)}
                <div class="comment-item comment-reply">
                  <div class="comment-avatar comment-avatar-sm">{getInitials(reply)}</div>
                  <div class="comment-content">
                    <div class="comment-header">
                      <span class="comment-author">{getDisplayName(reply)}</span>
                      {#if reply.is_external}
                        <span class="comment-badge-external">External</span>
                      {/if}
                      <span class="comment-time">{timeAgo(reply.created_at)}</span>
                    </div>
                    <div class="comment-body">{reply.body}</div>
                  </div>
                </div>
              {/each}
            </div>
          {/if}

          <!-- Reply form -->
          {#if replyTo === comment.id}
            <div class="comment-reply-form">
              <textarea
                class="comment-textarea"
                placeholder="Write a reply..."
                bind:value={replyBody}
                onkeydown={(e) => { if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) submitReply(comment.id); }}
                rows="2"
              ></textarea>
              <div class="comment-reply-actions">
                <button class="btn btn-primary btn-sm" onclick={() => submitReply(comment.id)} disabled={submitting || !replyBody.trim()}>Reply</button>
                <button class="btn btn-secondary btn-sm" onclick={() => { replyTo = null; replyBody = ''; }}>Cancel</button>
              </div>
            </div>
          {/if}
        </div>
      {/each}
    {/if}
  </div>

  <!-- New comment -->
  <div class="comment-compose">
    <div class="comment-compose-inner">
      <textarea
        class="comment-textarea"
        placeholder="Leave a comment... (@ to mention)"
        bind:value={newBody}
        onkeydown={handleKeydown}
        oninput={handleInput}
        rows="2"
      ></textarea>
      {#if showMentionList && filteredUsers.length > 0}
        <div class="comment-mention-list">
          {#each filteredUsers as u}
            <button class="comment-mention-item" onclick={() => insertMention(u.username)}>
              <span class="comment-mention-name">{u.display_name || u.username}</span>
              <span class="comment-mention-username">@{u.username}</span>
            </button>
          {/each}
        </div>
      {/if}
      <div class="comment-compose-actions">
        <span class="comment-compose-hint">Cmd+Enter to send</span>
        <button class="btn btn-primary btn-sm" onclick={submitComment} disabled={submitting || !newBody.trim()}>
          {submitting ? 'Sending...' : 'Comment'}
        </button>
      </div>
    </div>
  </div>
</div>

<style>
  .comments-panel {
    display: flex;
    flex-direction: column;
    height: 100%;
    min-height: 0;
  }

  .comments-filters {
    display: flex;
    gap: 2px;
    padding: 8px 0;
    border-bottom: 1px solid var(--border);
  }

  .comments-filter-btn {
    display: flex;
    align-items: center;
    gap: 4px;
    padding: 4px 10px;
    background: none;
    border: none;
    font-size: 13px;
    color: var(--text-secondary);
    cursor: pointer;
    border-radius: var(--radius-md);
    transition: color 0.1s, background 0.1s;
  }

  .comments-filter-btn:hover { color: var(--text-primary); background: var(--bg-secondary); }
  .comments-filter-btn.active { color: var(--text-primary); font-weight: 600; }

  .comments-count {
    font-size: 11px;
    color: var(--text-muted);
    font-variant-numeric: tabular-nums;
  }

  .comments-list {
    flex: 1;
    overflow-y: auto;
    padding: 8px 0;
    min-height: 0;
  }

  .comments-empty {
    text-align: center;
    padding: 32px 16px;
    color: var(--text-muted);
    font-size: 13px;
  }

  .comment-thread {
    padding: 0 0 4px;
    margin-bottom: 4px;
  }

  .comment-thread.resolved { opacity: 0.55; }
  .comment-thread.resolved:hover { opacity: 0.85; }

  .comment-item {
    display: flex;
    gap: 10px;
    padding: 8px 0;
  }

  .comment-avatar {
    width: 28px;
    height: 28px;
    border-radius: 14px;
    background: var(--bg-tertiary, #e5e7eb);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 11px;
    font-weight: 600;
    color: var(--text-secondary);
    flex-shrink: 0;
  }

  .comment-avatar-sm {
    width: 22px;
    height: 22px;
    border-radius: 11px;
    font-size: 9px;
  }

  .comment-content {
    flex: 1;
    min-width: 0;
  }

  .comment-header {
    display: flex;
    align-items: center;
    gap: 6px;
    margin-bottom: 2px;
  }

  .comment-author {
    font-size: 13px;
    font-weight: 600;
    color: var(--text-primary);
  }

  .comment-badge-external {
    font-size: 10px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--accent, #3b82f6);
    background: var(--accent-bg, rgba(59,130,246,0.08));
    padding: 1px 5px;
    border-radius: 3px;
  }

  .comment-time {
    font-size: 12px;
    color: var(--text-muted);
    margin-left: auto;
  }

  .comment-body {
    font-size: 13px;
    color: var(--text-primary);
    line-height: 1.5;
    word-break: break-word;
    white-space: pre-wrap;
  }

  .comment-actions {
    display: flex;
    gap: 2px;
    margin-top: 4px;
  }

  .comment-action-btn {
    display: flex;
    align-items: center;
    gap: 4px;
    padding: 2px 6px;
    background: none;
    border: none;
    font-size: 12px;
    color: var(--text-muted);
    cursor: pointer;
    border-radius: 4px;
    transition: color 0.1s, background 0.1s;
  }

  .comment-action-btn:hover { color: var(--text-primary); background: var(--bg-secondary); }
  .comment-action-delete:hover { color: #ef4444; }

  .comment-replies {
    margin-left: 38px;
    border-left: 2px solid var(--border);
    padding-left: 12px;
  }

  .comment-reply { padding: 4px 0; }

  .comment-reply-form {
    margin-left: 38px;
    padding: 4px 0 8px;
  }

  .comment-reply-actions {
    display: flex;
    gap: 6px;
    margin-top: 6px;
  }

  .comment-textarea {
    width: 100%;
    padding: 8px 10px;
    border: 1px solid var(--border);
    border-radius: 6px;
    font: 13px/1.5 var(--font-sans, -apple-system, system-ui, sans-serif);
    color: var(--text-primary);
    background: var(--bg-primary);
    resize: none;
    box-sizing: border-box;
    transition: border-color 0.15s;
  }

  .comment-textarea:focus {
    outline: none;
    border-color: var(--accent, #3b82f6);
    box-shadow: 0 0 0 2px rgba(59,130,246,0.12);
  }

  .comment-compose {
    border-top: 1px solid var(--border);
    padding: 12px 0 4px;
    flex-shrink: 0;
  }

  .comment-compose-inner {
    position: relative;
  }

  .comment-compose-actions {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-top: 6px;
  }

  .comment-compose-hint {
    font-size: 11px;
    color: var(--text-muted);
  }

  .comment-mention-list {
    position: absolute;
    bottom: 100%;
    left: 0;
    right: 0;
    background: var(--bg-primary);
    border: 1px solid var(--border);
    border-radius: 6px;
    box-shadow: 0 -4px 12px rgba(0,0,0,0.1);
    z-index: 10;
    max-height: 150px;
    overflow-y: auto;
    margin-bottom: 4px;
  }

  .comment-mention-item {
    display: flex;
    align-items: center;
    gap: 8px;
    width: 100%;
    padding: 6px 10px;
    background: none;
    border: none;
    font-size: 13px;
    color: var(--text-primary);
    cursor: pointer;
    text-align: left;
  }

  .comment-mention-item:hover { background: var(--bg-secondary); }

  .comment-mention-username {
    font-size: 12px;
    color: var(--text-muted);
  }

  .btn-sm {
    padding: 4px 12px;
    font-size: 12px;
  }
</style>
