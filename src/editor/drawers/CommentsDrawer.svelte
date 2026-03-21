<script>
  import { request, getPagePath } from '../api.js';

  let {
    pageId = null,
    apiUrl = '/outpost/api.php',
    csrfToken = '',
    userId = null,
    userName = '',
  } = $props();

  let commentsList = $state([]);
  let loading = $state(true);
  let newBody = $state('');
  let filter = $state('all');
  let replyTo = $state(null);
  let replyBody = $state('');
  let submitting = $state(false);
  let teamUsers = $state([]);
  let mentionQuery = $state('');
  let showMentionList = $state(false);

  let filtered = $derived(
    filter === 'all' ? commentsList :
    commentsList.filter(c => c.status === filter)
  );

  let openCount = $derived(commentsList.filter(c => c.status === 'open').length);
  let resolvedCount = $derived(commentsList.filter(c => c.status === 'resolved').length);

  let filteredUsers = $derived(
    teamUsers.filter(u =>
      u.username.toLowerCase().includes(mentionQuery) ||
      (u.display_name || '').toLowerCase().includes(mentionQuery)
    ).slice(0, 5)
  );

  $effect(() => {
    if (pageId) {
      loadComments();
      loadUsers();
    }
  });

  async function loadComments() {
    loading = true;
    try {
      const data = await request('comments', {
        params: {
          entity_type: 'page',
          entity_id: String(pageId),
          page_path: getPagePath(),
        },
      });
      commentsList = data.comments || [];
    } catch {
      commentsList = [];
    } finally {
      loading = false;
    }
  }

  async function loadUsers() {
    try {
      const data = await request('users');
      teamUsers = data.users || [];
    } catch {
      // non-admins may not have access
    }
  }

  async function submitComment() {
    if (!newBody.trim() || submitting) return;
    submitting = true;
    try {
      await request('comments', {
        method: 'POST',
        body: {
          entity_type: 'page',
          entity_id: pageId,
          page_path: getPagePath(),
          body: newBody.trim(),
        },
      });
      newBody = '';
      await loadComments();
    } catch (e) {
      console.error('[OPE] Comment error:', e);
    } finally {
      submitting = false;
    }
  }

  async function submitReply(parentId) {
    if (!replyBody.trim() || submitting) return;
    submitting = true;
    try {
      await request('comments', {
        method: 'POST',
        body: {
          entity_type: 'page',
          entity_id: pageId,
          page_path: getPagePath(),
          body: replyBody.trim(),
          parent_id: parentId,
        },
      });
      replyBody = '';
      replyTo = null;
      await loadComments();
    } catch (e) {
      console.error('[OPE] Reply error:', e);
    } finally {
      submitting = false;
    }
  }

  async function toggleResolve(comment) {
    const newStatus = comment.status === 'open' ? 'resolved' : 'open';
    try {
      await request('comments', {
        method: 'PUT',
        params: { id: String(comment.id) },
        body: { status: newStatus },
      });
      await loadComments();
    } catch (e) {
      console.error('[OPE] Resolve error:', e);
    }
  }

  async function deleteComment(id) {
    try {
      await request('comments', {
        method: 'DELETE',
        params: { id: String(id) },
      });
      await loadComments();
    } catch (e) {
      console.error('[OPE] Delete error:', e);
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
    if (!dateStr) return '';
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
</script>

<div class="ope-comments-drawer">
  <!-- Filter tabs -->
  <div class="ope-comments-filters">
    <button class="ope-comments-filter" class:ope-comments-filter-active={filter === 'all'} onclick={() => { filter = 'all'; }}>
      All <span class="ope-comments-count">{commentsList.length}</span>
    </button>
    <button class="ope-comments-filter" class:ope-comments-filter-active={filter === 'open'} onclick={() => { filter = 'open'; }}>
      Open <span class="ope-comments-count">{openCount}</span>
    </button>
    <button class="ope-comments-filter" class:ope-comments-filter-active={filter === 'resolved'} onclick={() => { filter = 'resolved'; }}>
      Resolved <span class="ope-comments-count">{resolvedCount}</span>
    </button>
  </div>

  <!-- Comment list -->
  <div class="ope-comments-list">
    {#if loading}
      <div class="ope-comments-empty">Loading...</div>
    {:else if filtered.length === 0}
      <div class="ope-comments-empty">
        {#if filter === 'all'}
          <svg class="ope-comments-empty-icon" viewBox="0 0 24 24" fill="none" stroke="#D1D5DB" stroke-width="1.5">
            <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
          </svg>
          <p>No comments yet</p>
          <p class="ope-comments-empty-sub">Start the conversation.</p>
        {:else}
          <p>No {filter} comments.</p>
        {/if}
      </div>
    {:else}
      {#each filtered as comment (comment.id)}
        <div class="ope-comments-thread" class:ope-comments-resolved={comment.status === 'resolved'}>
          <div class="ope-comments-item">
            <div class="ope-comments-avatar">{getInitials(comment)}</div>
            <div class="ope-comments-content">
              <div class="ope-comments-header">
                <span class="ope-comments-author">{getDisplayName(comment)}</span>
                {#if comment.is_external}
                  <span class="ope-comments-badge">External</span>
                {/if}
                <span class="ope-comments-time">{timeAgo(comment.created_at)}</span>
              </div>
              <div class="ope-comments-body">{comment.body}</div>
              <div class="ope-comments-actions">
                <button class="ope-comments-action" onclick={() => toggleResolve(comment)}>
                  {#if comment.status === 'resolved'}
                    Reopen
                  {:else}
                    <svg viewBox="0 0 16 16" fill="none" stroke="#10B981" stroke-width="2" width="12" height="12"><polyline points="3 8 6.5 11.5 13 5"/></svg>
                    Resolve
                  {/if}
                </button>
                <button class="ope-comments-action" onclick={() => { replyTo = replyTo === comment.id ? null : comment.id; replyBody = ''; }}>
                  Reply
                </button>
                {#if comment.user_id === userId}
                  <button class="ope-comments-action ope-comments-action-delete" onclick={() => deleteComment(comment.id)}>
                    Delete
                  </button>
                {/if}
              </div>
            </div>
          </div>

          <!-- Replies -->
          {#if comment.replies?.length}
            <div class="ope-comments-replies">
              {#each comment.replies as reply (reply.id)}
                <div class="ope-comments-item ope-comments-reply-item">
                  <div class="ope-comments-avatar ope-comments-avatar-sm">{getInitials(reply)}</div>
                  <div class="ope-comments-content">
                    <div class="ope-comments-header">
                      <span class="ope-comments-author">{getDisplayName(reply)}</span>
                      {#if reply.is_external}
                        <span class="ope-comments-badge">External</span>
                      {/if}
                      <span class="ope-comments-time">{timeAgo(reply.created_at)}</span>
                    </div>
                    <div class="ope-comments-body">{reply.body}</div>
                  </div>
                </div>
              {/each}
            </div>
          {/if}

          <!-- Reply form -->
          {#if replyTo === comment.id}
            <div class="ope-comments-reply-form">
              <textarea
                class="ope-comments-textarea"
                placeholder="Write a reply..."
                bind:value={replyBody}
                onkeydown={(e) => { if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) submitReply(comment.id); }}
                rows="2"
              ></textarea>
              <div class="ope-comments-reply-actions">
                <button class="ope-comments-btn-primary" onclick={() => submitReply(comment.id)} disabled={submitting || !replyBody.trim()}>Reply</button>
                <button class="ope-comments-btn-cancel" onclick={() => { replyTo = null; replyBody = ''; }}>Cancel</button>
              </div>
            </div>
          {/if}
        </div>
      {/each}
    {/if}
  </div>

  <!-- New comment -->
  <div class="ope-comments-compose">
    <div class="ope-comments-compose-inner">
      <textarea
        class="ope-comments-textarea"
        placeholder="Leave a comment... (@ to mention)"
        bind:value={newBody}
        onkeydown={handleKeydown}
        oninput={handleInput}
        rows="2"
      ></textarea>
      {#if showMentionList && filteredUsers.length > 0}
        <div class="ope-comments-mention-list">
          {#each filteredUsers as u}
            <button class="ope-comments-mention-item" onclick={() => insertMention(u.username)}>
              <span class="ope-comments-mention-name">{u.display_name || u.username}</span>
              <span class="ope-comments-mention-handle">@{u.username}</span>
            </button>
          {/each}
        </div>
      {/if}
      <div class="ope-comments-compose-footer">
        <span class="ope-comments-compose-hint">Cmd+Enter to send</span>
        <button class="ope-comments-btn-primary" onclick={submitComment} disabled={submitting || !newBody.trim()}>
          {submitting ? 'Sending...' : 'Comment'}
        </button>
      </div>
    </div>
  </div>
</div>

<style>
  .ope-comments-drawer {
    display: flex;
    flex-direction: column;
    height: 100%;
    min-height: 0;
  }

  .ope-comments-filters {
    display: flex;
    gap: 0;
    padding: 0 20px;
    border-bottom: 1px solid #F3F4F6;
    flex-shrink: 0;
  }

  .ope-comments-filter {
    display: flex;
    align-items: center;
    gap: 4px;
    padding: 10px 12px;
    background: none;
    border: none;
    border-bottom: 2px solid transparent;
    font-size: 13px;
    font-weight: 500;
    color: #9CA3AF;
    cursor: pointer;
    transition: all 0.15s;
    font-family: inherit;
  }
  .ope-comments-filter:hover { color: #374151; }
  .ope-comments-filter-active { color: #111827; border-bottom-color: #2D5A47; }

  .ope-comments-count {
    font-size: 11px;
    color: #D1D5DB;
    font-variant-numeric: tabular-nums;
  }
  .ope-comments-filter-active .ope-comments-count { color: #9CA3AF; }

  .ope-comments-list {
    flex: 1;
    overflow-y: auto;
    min-height: 0;
    padding: 0;
  }

  .ope-comments-empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 48px 20px;
    text-align: center;
    color: #9CA3AF;
    font-size: 13px;
  }
  .ope-comments-empty p { margin: 0 0 4px; }
  .ope-comments-empty-icon { width: 28px; height: 28px; margin-bottom: 12px; }
  .ope-comments-empty-sub { font-size: 12px; color: #D1D5DB; }

  .ope-comments-thread {
    padding: 0 20px;
    border-bottom: 1px solid #F3F4F6;
  }
  .ope-comments-thread:last-child { border-bottom: none; }
  .ope-comments-resolved { opacity: 0.55; }
  .ope-comments-resolved:hover { opacity: 0.85; }

  .ope-comments-item {
    display: flex;
    gap: 10px;
    padding: 12px 0;
  }

  .ope-comments-avatar {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    background: #F3F4F6;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 10px;
    font-weight: 600;
    color: #6B7280;
    flex-shrink: 0;
  }
  .ope-comments-avatar-sm { width: 22px; height: 22px; font-size: 8px; }

  .ope-comments-content { flex: 1; min-width: 0; }

  .ope-comments-header {
    display: flex;
    align-items: center;
    gap: 6px;
    margin-bottom: 2px;
  }

  .ope-comments-author { font-size: 13px; font-weight: 600; color: #111827; }

  .ope-comments-badge {
    font-size: 10px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: #2D5A47;
    background: rgba(45, 90, 71, 0.08);
    padding: 1px 5px;
    border-radius: 3px;
  }

  .ope-comments-time { font-size: 12px; color: #D1D5DB; margin-left: auto; }

  .ope-comments-body {
    font-size: 13px;
    color: #374151;
    line-height: 1.5;
    word-break: break-word;
    white-space: pre-wrap;
  }

  .ope-comments-actions {
    display: flex;
    gap: 2px;
    margin-top: 4px;
  }

  .ope-comments-action {
    display: flex;
    align-items: center;
    gap: 3px;
    padding: 2px 6px;
    background: none;
    border: none;
    font-size: 12px;
    color: #9CA3AF;
    cursor: pointer;
    border-radius: 4px;
    transition: all 0.1s;
    font-family: inherit;
  }
  .ope-comments-action:hover { color: #374151; background: #F3F4F6; }
  .ope-comments-action-delete:hover { color: #EF4444; }

  .ope-comments-replies {
    margin-left: 38px;
    border-left: 2px solid #F3F4F6;
    padding-left: 12px;
  }
  .ope-comments-reply-item { padding: 6px 0; }

  .ope-comments-reply-form {
    margin-left: 38px;
    padding: 0 0 12px;
  }
  .ope-comments-reply-actions {
    display: flex;
    gap: 6px;
    margin-top: 6px;
  }

  .ope-comments-textarea {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid #E5E7EB;
    border-radius: 8px;
    font-size: 13px;
    line-height: 1.5;
    color: #111827;
    background: #fff;
    resize: none;
    box-sizing: border-box;
    outline: none;
    font-family: inherit;
    transition: border-color 0.15s;
  }
  .ope-comments-textarea:focus {
    border-color: #2D5A47;
    box-shadow: 0 0 0 2px rgba(45, 90, 71, 0.08);
  }
  .ope-comments-textarea::placeholder { color: #D1D5DB; }

  .ope-comments-compose {
    border-top: 1px solid #F3F4F6;
    padding: 12px 20px 16px;
    flex-shrink: 0;
  }
  .ope-comments-compose-inner { position: relative; }
  .ope-comments-compose-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-top: 6px;
  }
  .ope-comments-compose-hint { font-size: 11px; color: #D1D5DB; }

  .ope-comments-btn-primary {
    padding: 5px 14px;
    background: #2D5A47;
    color: white;
    border: none;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 500;
    cursor: pointer;
    transition: background 0.15s;
    font-family: inherit;
  }
  .ope-comments-btn-primary:hover:not(:disabled) { background: #1E4535; }
  .ope-comments-btn-primary:disabled { opacity: 0.4; cursor: default; }

  .ope-comments-btn-cancel {
    padding: 5px 14px;
    background: none;
    border: none;
    color: #9CA3AF;
    font-size: 12px;
    font-weight: 500;
    cursor: pointer;
    font-family: inherit;
    transition: color 0.15s;
  }
  .ope-comments-btn-cancel:hover { color: #374151; }

  .ope-comments-mention-list {
    position: absolute;
    bottom: 100%;
    left: 0;
    right: 0;
    background: #fff;
    border: 1px solid #E5E7EB;
    border-radius: 8px;
    box-shadow: 0 -4px 16px rgba(0, 0, 0, 0.08);
    z-index: 10;
    max-height: 150px;
    overflow-y: auto;
    margin-bottom: 4px;
  }

  .ope-comments-mention-item {
    display: flex;
    align-items: center;
    gap: 8px;
    width: 100%;
    padding: 8px 12px;
    background: none;
    border: none;
    font-size: 13px;
    color: #111827;
    cursor: pointer;
    text-align: left;
    font-family: inherit;
  }
  .ope-comments-mention-item:hover { background: #F9FAFB; }
  .ope-comments-mention-handle { font-size: 12px; color: #9CA3AF; }
</style>
