<script>
  import { onMount } from 'svelte';
  import { members as membersApi } from '$lib/api.js';
  import { addToast } from '$lib/stores.js';
  import { timeAgo } from '$lib/utils.js';

  let { settings = {}, onSettingChange = () => {} } = $props();

  let membersList = $state([]);
  let loading = $state(true);

  const avatarColors = ['#4A8B72', '#C4785C', '#7D9B8A', '#B85C4A', '#5B8C5A', '#C49A3D', '#6B8FA3', '#9B7EB8'];

  function getAvatarColor(name) {
    let hash = 0;
    for (let i = 0; i < (name || '').length; i++) hash = name.charCodeAt(i) + ((hash << 5) - hash);
    return avatarColors[Math.abs(hash) % avatarColors.length];
  }

  function getInitial(name) {
    return (name || '?')[0].toUpperCase();
  }

  let freeCount = $derived(membersList.filter(m => m.role === 'free_member').length);
  let paidCount = $derived(membersList.filter(m => m.role === 'paid_member').length);
  let suspendedCount = $derived(membersList.filter(m => m.member_status === 'suspended').length);
  let unverifiedCount = $derived(membersList.filter(m => !m.email_verified).length);

  onMount(() => { loadMembers(); });

  async function loadMembers() {
    loading = true;
    try {
      const data = await membersApi.list();
      membersList = data.members || [];
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      loading = false;
    }
  }

  async function changeRole(member, newRole) {
    try {
      await membersApi.update(member.id, { role: newRole });
      member.role = newRole;
      membersList = [...membersList];
      addToast('Role updated', 'success');
    } catch (err) {
      addToast(err.message, 'error');
    }
  }

  async function toggleStatus(member) {
    const newStatus = member.member_status === 'suspended' ? 'active' : 'suspended';
    try {
      await membersApi.update(member.id, { member_status: newStatus });
      member.member_status = newStatus;
      membersList = [...membersList];
      addToast(newStatus === 'suspended' ? 'Member suspended' : 'Member activated', 'success');
    } catch (err) {
      addToast(err.message, 'error');
    }
  }

  async function deleteMember(member) {
    if (!confirm(`Delete member "${member.username}"? This cannot be undone.`)) return;
    try {
      await membersApi.delete(member.id);
      membersList = membersList.filter(m => m.id !== member.id);
      addToast('Member deleted', 'success');
    } catch (err) {
      addToast(err.message, 'error');
    }
  }

  async function verifyMember(member) {
    try {
      await membersApi.update(member.id, { email_verified: true });
      member.email_verified = new Date().toISOString();
      membersList = [...membersList];
      addToast('Email verified', 'success');
    } catch (err) {
      addToast(err.message, 'error');
    }
  }
</script>

<div class="settings-section">
  <h3 class="settings-section-title">Members</h3>
  <p class="settings-section-desc">Manage front-end members and membership settings.</p>

  <!-- Email verification toggle -->
  <div class="form-group" style="margin-bottom: var(--space-xl);">
    <label class="form-label">Require Email Verification</label>
    <div style="display: flex; align-items: center; gap: var(--space-md);">
      <button
        class="toggle"
        class:active={settings.require_email_verification === '1'}
        onclick={() => onSettingChange('require_email_verification', settings.require_email_verification === '1' ? '0' : '1')}
        type="button"
      ></button>
    </div>
    <p class="form-hint">When enabled, new members must verify their email address before they can sign in.</p>
  </div>

  {#if loading}
    <div class="loading-overlay"><div class="spinner"></div></div>
  {:else}
    <!-- Stats -->
    <div class="members-stats">
      <div class="members-stat">
        <span class="members-stat-value">{freeCount}</span>
        <span class="members-stat-label">Free</span>
      </div>
      <div class="members-stat">
        <span class="members-stat-value">{paidCount}</span>
        <span class="members-stat-label">Paid</span>
      </div>
      <div class="members-stat">
        <span class="members-stat-value">{suspendedCount}</span>
        <span class="members-stat-label">Suspended</span>
      </div>
      {#if unverifiedCount > 0}
        <div class="members-stat">
          <span class="members-stat-value">{unverifiedCount}</span>
          <span class="members-stat-label">Unverified</span>
        </div>
      {/if}
    </div>

    {#if membersList.length === 0}
      <div class="empty-state">
        <div class="empty-state-title">No members yet</div>
        <p style="font-size: var(--font-size-sm); color: var(--text-secondary);">
          Members will appear here when they register on the site.
        </p>
      </div>
    {:else}
      <div class="members-table-header">
        <div class="members-col-name">Member</div>
        <div class="members-col-role">Tier</div>
        <div class="members-col-status">Status</div>
        <div class="members-col-actions"></div>
      </div>

      <div class="members-list">
        {#each membersList as member (member.id)}
          <div class="members-row">
            <div class="members-col-name">
              <div class="member-avatar" style="background-color: {getAvatarColor(member.username)};">
                {getInitial(member.username)}
              </div>
              <div class="member-info">
                <span class="member-name">{member.username}</span>
                {#if member.email}
                  <span class="member-email">
                    {member.email}
                    {#if !member.email_verified}
                      <span class="member-unverified">unverified</span>
                    {/if}
                  </span>
                {/if}
              </div>
            </div>
            <div class="members-col-role">
              <select
                class="member-role-select"
                value={member.role}
                onchange={(e) => changeRole(member, e.target.value)}
              >
                <option value="free_member">Free</option>
                <option value="paid_member">Paid</option>
              </select>
            </div>
            <div class="members-col-status">
              <button
                class="member-status-toggle"
                class:suspended={member.member_status === 'suspended'}
                onclick={() => toggleStatus(member)}
              >
                {member.member_status === 'suspended' ? 'Suspended' : 'Active'}
              </button>
            </div>
            <div class="members-col-actions">
              {#if !member.email_verified}
                <button class="member-action-btn" onclick={() => verifyMember(member)} title="Verify email">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                </button>
              {/if}
              <button class="member-action-btn danger" onclick={() => deleteMember(member)} title="Delete member">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/></svg>
              </button>
            </div>
          </div>
        {/each}
      </div>
    {/if}
  {/if}
</div>

<style>
  .form-hint {
    font-size: var(--font-size-xs);
    color: var(--text-tertiary);
    margin-top: var(--space-xs);
  }
  .members-stats {
    display: flex;
    gap: var(--space-lg);
    margin-bottom: var(--space-xl);
  }
  .members-stat { display: flex; flex-direction: column; }
  .members-stat-value { font-size: 1.5rem; font-weight: 600; color: var(--text-primary); }
  .members-stat-label {
    font-size: 11px; font-weight: 600; text-transform: uppercase;
    letter-spacing: 0.05em; color: var(--text-tertiary);
  }
  .members-table-header {
    display: flex; align-items: center; padding: 0 var(--space-lg); margin-bottom: var(--space-xs);
  }
  .members-table-header > div {
    font-size: 11px; font-weight: 600; text-transform: uppercase;
    letter-spacing: 0.05em; color: var(--text-tertiary);
  }
  .members-list { display: flex; flex-direction: column; }
  .members-row {
    display: flex; align-items: center; padding: var(--space-md) var(--space-lg);
    border-radius: var(--radius-sm); transition: background-color 0.15s ease;
  }
  .members-row:hover { background-color: var(--bg-hover); }
  .members-col-name { flex: 1; display: flex; align-items: center; gap: var(--space-md); min-width: 0; }
  .members-col-role { width: 100px; flex-shrink: 0; }
  .members-col-status { width: 100px; flex-shrink: 0; }
  .members-col-actions { width: 70px; flex-shrink: 0; display: flex; gap: 2px; justify-content: flex-end; }
  .member-avatar {
    width: 36px; height: 36px; border-radius: 50%; display: flex;
    align-items: center; justify-content: center; color: white;
    font-size: 13px; font-weight: 600; flex-shrink: 0;
  }
  .member-info { display: flex; flex-direction: column; min-width: 0; }
  .member-name { font-size: 14px; font-weight: 600; color: var(--text-primary); }
  .member-email { font-size: 12px; color: var(--text-tertiary); }
  .member-role-select {
    background: none; border: 1px solid transparent; font-size: var(--font-size-sm);
    color: var(--text-secondary); padding: 2px 4px; border-radius: var(--radius-sm);
    cursor: pointer; font-family: var(--font-sans);
  }
  .member-role-select:hover { border-color: var(--border); }
  .member-status-toggle {
    background: none; border: none; font-size: var(--font-size-sm);
    color: var(--text-secondary); cursor: pointer; font-family: var(--font-sans);
    padding: 2px 6px; border-radius: var(--radius-sm);
  }
  .member-status-toggle:hover { background: var(--bg-hover); }
  .member-status-toggle.suspended { color: var(--danger, #e53e3e); }
  .member-action-btn {
    opacity: 0; background: none; border: none; padding: var(--space-xs);
    cursor: pointer; color: var(--text-tertiary); border-radius: var(--radius-sm);
    display: flex; align-items: center; justify-content: center;
    transition: opacity 0.15s ease, color 0.15s ease;
  }
  .members-row:hover .member-action-btn { opacity: 1; }
  .member-action-btn:hover { color: var(--success, #16a34a); }
  .member-action-btn.danger:hover { color: var(--danger, #e53e3e); }
  .member-unverified { font-size: 10px; color: var(--warning, #d97706); margin-left: 4px; }

  @media (max-width: 768px) {
    .members-table-header {
      display: none;
    }

    .members-row {
      flex-wrap: wrap;
      gap: var(--space-sm);
      padding: var(--space-md);
    }

    .members-col-name {
      flex: 1 1 100%;
    }

    .members-col-role {
      width: auto;
    }

    .members-col-status {
      width: auto;
    }

    .members-col-actions {
      width: auto;
      margin-left: auto;
    }

    .member-action-btn {
      opacity: 1;
    }

    .members-stats {
      flex-wrap: wrap;
    }
  }
</style>
