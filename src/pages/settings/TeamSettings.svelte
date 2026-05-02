<script>
  import { onMount } from 'svelte';
  import { users as usersApi } from '$lib/api.js';
  import { addToast, navigate, isAdmin } from '$lib/stores.js';
  import { timeAgo } from '$lib/utils.js';

  let usersList = $state([]);
  let loading = $state(true);
  let showModal = $state(false);
  let creating = $state(false);

  let formUsername = $state('');
  let formEmail = $state('');
  let formPassword = $state('');
  let formRole = $state('admin');

  const config = window.__KENII_CONFIG__ || {};
  const currentUserId = config.user?.id;
  let admin = $derived($isAdmin);

  const avatarColors = ['#4A8B72', '#C4785C', '#7D9B8A', '#B85C4A', '#5B8C5A', '#C49A3D', '#6B8FA3', '#9B7EB8'];

  function getAvatarColor(name) {
    let hash = 0;
    for (let i = 0; i < name.length; i++) hash = name.charCodeAt(i) + ((hash << 5) - hash);
    return avatarColors[Math.abs(hash) % avatarColors.length];
  }

  function getInitial(name) {
    return (name || '?')[0].toUpperCase();
  }

  onMount(() => { loadUsers(); });

  async function loadUsers() {
    loading = true;
    try {
      const data = await usersApi.list();
      usersList = data.users || [];
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      loading = false;
    }
  }

  function openModal() {
    formUsername = '';
    formEmail = '';
    formPassword = '';
    formRole = 'admin';
    showModal = true;
  }

  function closeModal() {
    showModal = false;
  }

  async function handleCreate() {
    if (!formUsername.trim() || !formPassword) {
      addToast('Username and password are required', 'error');
      return;
    }
    if (formPassword.length < 8) {
      addToast('Password must be at least 8 characters', 'error');
      return;
    }
    creating = true;
    try {
      await usersApi.create({
        username: formUsername.trim(),
        email: formEmail.trim(),
        password: formPassword,
        role: formRole,
      });
      addToast('User created', 'success');
      showModal = false;
      await loadUsers();
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      creating = false;
    }
  }

  async function handleDelete(usr) {
    if (usr.id == currentUserId) {
      addToast('You cannot delete your own account', 'error');
      return;
    }
    if (!confirm(`Delete user "${usr.username}"? This cannot be undone.`)) return;
    try {
      await usersApi.delete(usr.id);
      usersList = usersList.filter((u) => u.id !== usr.id);
      addToast('User deleted', 'success');
    } catch (err) {
      addToast(err.message, 'error');
    }
  }

  function handleKeydown(e) {
    if (e.key === 'Escape') closeModal();
  }
</script>

<div class="settings-section">
  <div class="settings-section-header">
    <div>
      <h3 class="settings-section-title">Team</h3>
      <p class="settings-section-desc">{usersList.length} user{usersList.length !== 1 ? 's' : ''} with admin access.</p>
    </div>
    {#if admin}
      <button class="btn btn-primary" onclick={openModal}>
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Invite User
      </button>
    {/if}
  </div>

  {#if loading}
    <div class="loading-overlay"><div class="spinner"></div></div>
  {:else if usersList.length === 0}
    <div class="empty-state">
      <div class="empty-state-title">No users yet</div>
      <p style="font-size: var(--font-size-sm); color: var(--sec);">
        Create your first user to get started.
      </p>
    </div>
  {:else}
    <div class="users-table-header">
      <div class="users-col-name">Name</div>
      <div class="users-col-role">Role</div>
      <div class="users-col-active">Last Active</div>
    </div>

    <div class="users-list">
      {#each usersList as usr (usr.id)}
        <div
          class="users-row"
          onclick={() => navigate('user-profile', { userId: usr.id })}
          role="button"
          tabindex="0"
          onkeydown={(e) => e.key === 'Enter' && navigate('user-profile', { userId: usr.id })}
        >
          <div class="users-col-name">
            <div class="user-avatar" style="background-color: {getAvatarColor(usr.username)};">
              {getInitial(usr.username)}
            </div>
            <div class="user-info">
              <span class="user-name">{usr.username}</span>
              {#if usr.email}
                <span class="user-email">{usr.email}</span>
              {/if}
            </div>
          </div>
          <div class="users-col-role">
            <span class="user-role">{
              ({ admin: 'Admin', developer: 'Developer', editor: 'Editor', free_member: 'Free Member', paid_member: 'Paid Member' })[usr.role] || usr.role
            }</span>
          </div>
          <div class="users-col-active">
            <span class="user-active">{usr.last_login ? timeAgo(usr.last_login) : 'Never'}</span>
            {#if admin && usr.id != currentUserId}
              <button
                class="user-delete-btn"
                onclick={(e) => { e.stopPropagation(); handleDelete(usr); }}
                title="Delete user"
              >
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/></svg>
              </button>
            {/if}
          </div>
        </div>
      {/each}
    </div>
  {/if}
</div>

<!-- Invite User Modal -->
{#if showModal}
  <div
    class="modal-overlay"
    onclick={closeModal}
    onkeydown={handleKeydown}
    role="dialog"
    aria-modal="true"
    aria-label="Invite User"
    tabindex="-1"
  >
    <div class="modal" onclick={(e) => e.stopPropagation()}>
      <div class="modal-header">
        <h2 class="modal-title">Invite User</h2>
        <button class="btn btn-ghost btn-sm" onclick={closeModal} aria-label="Close">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
      </div>

      <div style="padding: var(--space-lg);">
        <div class="form-group">
          <label class="form-label" for="user-username">Username</label>
          <input id="user-username" class="input" type="text" bind:value={formUsername} placeholder="e.g. johndoe" autocomplete="off" />
        </div>
        <div class="form-group">
          <label class="form-label" for="user-email">Email</label>
          <input id="user-email" class="input" type="email" bind:value={formEmail} placeholder="e.g. john@example.com" />
        </div>
        <div class="form-group">
          <label class="form-label" for="user-password">Password</label>
          <input id="user-password" class="input" type="password" bind:value={formPassword} placeholder="Minimum 8 characters" autocomplete="new-password" />
        </div>
        <div class="form-group">
          <label class="form-label" for="user-role">Role</label>
          <select id="user-role" class="input" bind:value={formRole}>
            <optgroup label="Internal (Admin Panel)">
              <option value="admin">Admin</option>
              <option value="developer">Developer</option>
              <option value="editor">Editor</option>
            </optgroup>
            <optgroup label="External (Front-end)">
              <option value="free_member">Free Member</option>
              <option value="paid_member">Paid Member</option>
            </optgroup>
          </select>
        </div>
      </div>

      <div class="modal-footer">
        <button class="btn btn-secondary" onclick={closeModal}>Cancel</button>
        <button class="btn btn-primary" onclick={handleCreate} disabled={creating}>
          {creating ? 'Creating...' : 'Create User'}
        </button>
      </div>
    </div>
  </div>
{/if}

<style>
  .settings-section-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    margin-bottom: var(--space-xl);
  }

  .users-table-header {
    display: flex;
    align-items: center;
    padding: 0 var(--space-lg);
    margin-bottom: var(--space-xs);
  }
  .users-table-header > div {
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--dim);
  }
  .users-list {
    display: flex;
    flex-direction: column;
  }
  .users-row {
    display: flex;
    align-items: center;
    padding: var(--space-md) var(--space-lg);
    border-radius: var(--radius-sm);
    transition: background-color 0.15s ease;
    cursor: pointer;
  }
  .users-row:hover {
    background-color: var(--hover);
  }
  .users-col-name {
    flex: 1;
    display: flex;
    align-items: center;
    gap: var(--space-md);
    min-width: 0;
  }
  .users-col-role { width: 120px; flex-shrink: 0; }
  .users-col-active {
    width: 140px;
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: var(--space-sm);
  }
  .user-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 14px;
    font-weight: 600;
    flex-shrink: 0;
    user-select: none;
  }
  .user-info {
    display: flex;
    flex-direction: column;
    min-width: 0;
  }
  .user-name {
    font-size: 15px;
    font-weight: 600;
    color: var(--text);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }
  .user-email {
    font-size: 13px;
    color: var(--dim);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }
  .user-role {
    font-size: var(--font-size-sm);
    color: var(--sec);
  }
  .user-active {
    font-size: var(--font-size-sm);
    color: var(--dim);
  }
  .user-delete-btn {
    opacity: 0;
    background: none;
    border: none;
    padding: var(--space-xs);
    cursor: pointer;
    color: var(--dim);
    border-radius: var(--radius-sm);
    display: flex;
    align-items: center;
    justify-content: center;
    transition: opacity 0.15s ease, color 0.15s ease, background-color 0.15s ease;
  }
  .users-row:hover .user-delete-btn { opacity: 1; }
  .user-delete-btn:hover {
    color: var(--danger, #e53e3e);
    background-color: var(--hover);
  }

  @media (max-width: 768px) {
    .users-table-header {
      display: none;
    }

    .users-row {
      flex-wrap: wrap;
      gap: var(--space-sm);
      padding: var(--space-md);
    }

    .users-col-name {
      flex: 1 1 100%;
    }

    .users-col-role {
      width: auto;
      font-size: var(--font-size-xs);
    }

    .users-col-active {
      width: auto;
      flex: 1;
    }

    .user-delete-btn {
      opacity: 1;
    }
  }
</style>
