<script>
  import { onMount } from 'svelte';
  import { users as usersApi, media as mediaApi, auth } from '$lib/api.js';
  import { currentProfileUserId, user as currentUserStore, navigate, addToast } from '$lib/stores.js';
  import { required, email as emailRule, minLength, match, validate, hasErrors } from '$lib/validation.js';
  import QRCode from 'qrcode';

  let profileUserId = $derived($currentProfileUserId);
  let currentUser = $derived($currentUserStore);
  let profileUser = $state(null);
  let loading = $state(true);
  let saving = $state(false);
  let dirty = $state(false);

  // Form fields
  let formUsername = $state('');
  let formDisplayName = $state('');
  let formEmail = $state('');
  let formBio = $state('');
  let formAvatar = $state('');
  let formRole = $state('admin');
  let formPassword = $state('');
  let formPasswordConfirm = $state('');
  let showPasswordChange = $state(false);
  let errors = $state({});

  // 2FA state
  let twoFaEnabled = $state(false);
  let twoFaBackupCodesRemaining = $state(0);
  // 'idle' | 'setup' | 'backup_codes' | 'enabled' | 'disable_confirm' | 'regenerate_confirm'
  let twoFaView = $state('idle');
  let twoFaSecret = $state('');
  let twoFaUri = $state('');
  let twoFaQrDataUrl = $state('');
  let twoFaCode = $state('');
  let twoFaBackupCodes = $state([]);
  let twoFaPassword = $state('');
  let twoFaError = $state('');
  let twoFaLoading = $state(false);
  let twoFaCodesSaved = $state(false);

  function validateField(field) {
    const rules = {
      username: required(formUsername, 'Username'),
      email: emailRule(formEmail),
      password: formPassword ? minLength(formPassword, 8, 'Password') : '',
      passwordConfirm: formPassword ? match(formPassword, formPasswordConfirm) : '',
    };
    if (field) {
      const msg = rules[field];
      if (msg) errors = { ...errors, [field]: msg };
      else {
        const { [field]: _, ...rest } = errors;
        errors = rest;
      }
    } else {
      errors = validate(rules);
    }
    return !hasErrors(errors);
  }

  function clearError(field) {
    if (errors[field]) {
      const { [field]: _, ...rest } = errors;
      errors = rest;
    }
  }

  // Avatar colors (same as Users.svelte)
  const avatarColors = ['#4A8B72', '#C4785C', '#7D9B8A', '#B85C4A', '#5B8C5A', '#C49A3D', '#6B8FA3', '#9B7EB8'];
  function getAvatarColor(name) {
    let hash = 0;
    for (let i = 0; i < (name || '').length; i++) hash = name.charCodeAt(i) + ((hash << 5) - hash);
    return avatarColors[Math.abs(hash) % avatarColors.length];
  }

  let isOwnProfile = $derived(profileUser && currentUser && profileUser.id == currentUser.id);

  onMount(async () => {
    await loadUser();
  });

  async function loadUser() {
    if (!profileUserId) return;
    loading = true;
    try {
      const data = await usersApi.get(profileUserId);
      profileUser = data.user;
      formUsername = profileUser.username || '';
      formDisplayName = profileUser.display_name || '';
      formEmail = profileUser.email || '';
      formBio = profileUser.bio || '';
      formAvatar = profileUser.avatar || '';
      formRole = profileUser.role || 'admin';

      // Load 2FA status for own profile
      if (profileUser.id == $currentUserStore?.id) {
        try {
          const status = await auth.totpStatus();
          twoFaEnabled = status.enabled;
          twoFaBackupCodesRemaining = status.backup_codes_remaining;
          twoFaView = status.enabled ? 'enabled' : 'idle';
        } catch (_) {}
      }
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      loading = false;
    }
  }

  function markDirty() {
    dirty = true;
  }

  async function handleSave() {
    if (!profileUser) return;
    if (!validateField()) return;
    saving = true;
    try {
      const updateData = {
        username: formUsername,
        display_name: formDisplayName,
        email: formEmail,
        bio: formBio,
        avatar: formAvatar,
        role: formRole,
      };

      if (formPassword) {
        updateData.password = formPassword;
      }

      await usersApi.update(profileUser.id, updateData);
      dirty = false;
      formPassword = '';
      formPasswordConfirm = '';
      showPasswordChange = false;
      addToast('Profile saved', 'success');

      // Refresh the global user store so header/dashboard update immediately
      if (isOwnProfile) {
        try {
          const meData = await auth.me();
          if (meData.user) currentUserStore.set(meData.user);
        } catch (_) {}
      }
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      saving = false;
    }
  }

  async function handleAvatarUpload(e) {
    const file = e.target.files?.[0];
    if (!file) return;
    try {
      const result = await mediaApi.upload(file);
      if (result.media?.path) {
        const path = result.media.path;
        formAvatar = path.startsWith('/') ? path : '/' + path;
        dirty = true;
      }
    } catch (err) {
      addToast(err.message, 'error');
    }
  }

  function removeAvatar() {
    formAvatar = '';
    dirty = true;
  }

  function goBack() {
    navigate('settings', { section: 'team' });
  }

  // ── 2FA handlers ──────────────────────────────────

  async function startTwoFaSetup() {
    twoFaError = '';
    twoFaLoading = true;
    try {
      const data = await auth.totpSetup();
      twoFaSecret = data.secret;
      twoFaUri = data.uri;
      twoFaQrDataUrl = await QRCode.toDataURL(data.uri, { width: 200, margin: 2 });
      twoFaCode = '';
      twoFaView = 'setup';
    } catch (err) {
      twoFaError = err.message;
    } finally {
      twoFaLoading = false;
    }
  }

  async function confirmTwoFaEnable() {
    twoFaError = '';
    twoFaLoading = true;
    try {
      const data = await auth.totpEnable(twoFaCode);
      if (data.success) {
        twoFaBackupCodes = data.backup_codes;
        twoFaEnabled = true;
        twoFaCodesSaved = false;
        twoFaView = 'backup_codes';
      }
    } catch (err) {
      twoFaError = err.message;
    } finally {
      twoFaLoading = false;
    }
  }

  function finishBackupCodes() {
    twoFaBackupCodesRemaining = twoFaBackupCodes.length;
    twoFaBackupCodes = [];
    twoFaView = 'enabled';
  }

  async function copyBackupCodes() {
    try {
      await navigator.clipboard.writeText(twoFaBackupCodes.join('\n'));
      addToast('Backup codes copied', 'success');
    } catch (_) {}
  }

  async function startDisable() {
    twoFaPassword = '';
    twoFaError = '';
    twoFaView = 'disable_confirm';
  }

  async function confirmDisable() {
    twoFaError = '';
    twoFaLoading = true;
    try {
      await auth.totpDisable(twoFaPassword);
      twoFaEnabled = false;
      twoFaBackupCodesRemaining = 0;
      twoFaView = 'idle';
      addToast('Two-factor authentication disabled', 'success');
    } catch (err) {
      twoFaError = err.message;
    } finally {
      twoFaLoading = false;
    }
  }

  async function startRegenerate() {
    twoFaPassword = '';
    twoFaError = '';
    twoFaView = 'regenerate_confirm';
  }

  async function confirmRegenerate() {
    twoFaError = '';
    twoFaLoading = true;
    try {
      const data = await auth.totpBackupCodes(twoFaPassword);
      if (data.success) {
        twoFaBackupCodes = data.backup_codes;
        twoFaCodesSaved = false;
        twoFaView = 'backup_codes';
      }
    } catch (err) {
      twoFaError = err.message;
    } finally {
      twoFaLoading = false;
    }
  }
</script>

{#if loading}
  <div class="loading-overlay"><div class="spinner"></div></div>
{:else if profileUser}
  <div class="profile-page">
    <!-- Header -->
    <div class="profile-header">
      <button class="btn btn-ghost" onclick={goBack}>
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
        Team
      </button>
      <div class="profile-header-actions">
        {#if dirty}
          <span style="font-size: var(--font-size-sm); color: var(--warning);">Unsaved changes</span>
        {/if}
        <button class="btn btn-primary" onclick={handleSave} disabled={!dirty || saving}>
          {saving ? 'Saving...' : 'Save Profile'}
        </button>
      </div>
    </div>

    <!-- Avatar + Name hero -->
    <div class="profile-hero">
      <div class="profile-avatar-wrapper">
        {#if formAvatar}
          <img src={formAvatar} alt={formDisplayName || profileUser.username} class="profile-avatar-img" />
        {:else}
          <div class="profile-avatar-fallback" style="background-color: {getAvatarColor(profileUser.username)}">
            {(profileUser.username || '?')[0].toUpperCase()}
          </div>
        {/if}
        <label class="profile-avatar-upload" title="Upload avatar">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
          <input type="file" accept="image/*" onchange={handleAvatarUpload} style="display: none;" />
        </label>
      </div>
      {#if formAvatar}
        <button class="profile-avatar-remove" onclick={removeAvatar}>Remove photo</button>
      {/if}
    </div>

    <!-- Profile form -->
    <div class="profile-form">
      <div class="profile-section">
        <h3 class="profile-section-title">Profile</h3>

        <div class="profile-field">
          <label class="profile-label" for="profile-username">Username</label>
          <input
            id="profile-username"
            class="input"
            class:input-error={errors.username}
            type="text"
            bind:value={formUsername}
            oninput={() => { markDirty(); clearError('username'); }}
            onblur={() => validateField('username')}
            placeholder="username"
          />
          {#if errors.username}<span class="field-error">{errors.username}</span>{/if}
        </div>

        <div class="profile-field">
          <label class="profile-label" for="profile-display-name">Display Name</label>
          <input
            id="profile-display-name"
            class="input"
            type="text"
            bind:value={formDisplayName}
            oninput={markDirty}
            placeholder="How your name appears on the site"
          />
        </div>

        <div class="profile-field">
          <label class="profile-label" for="profile-email">Email</label>
          <input
            id="profile-email"
            class="input"
            class:input-error={errors.email}
            type="email"
            bind:value={formEmail}
            oninput={() => { markDirty(); clearError('email'); }}
            onblur={() => validateField('email')}
            placeholder="your@email.com"
          />
          {#if errors.email}<span class="field-error">{errors.email}</span>{/if}
        </div>

        <div class="profile-field">
          <label class="profile-label" for="profile-bio">Bio</label>
          <textarea
            id="profile-bio"
            class="input"
            rows="3"
            bind:value={formBio}
            oninput={markDirty}
            placeholder="A short bio about yourself..."
          ></textarea>
        </div>
      </div>

      <div class="profile-section">
        <h3 class="profile-section-title">Account</h3>

        <div class="profile-field">
          <label class="profile-label" for="profile-role">Role</label>
          <select
            id="profile-role"
            class="input"
            bind:value={formRole}
            onchange={markDirty}
          >
            <option value="admin">Admin</option>
            <option value="editor">Editor</option>
          </select>
        </div>

        {#if !showPasswordChange}
          <button class="btn btn-secondary" onclick={() => showPasswordChange = true}>
            Change Password
          </button>
        {:else}
          <div class="profile-field">
            <label class="profile-label" for="profile-password">New Password</label>
            <input
              id="profile-password"
              class="input"
              class:input-error={errors.password}
              type="password"
              bind:value={formPassword}
              oninput={() => { markDirty(); clearError('password'); }}
              onblur={() => validateField('password')}
              placeholder="Minimum 8 characters"
              autocomplete="new-password"
            />
            {#if errors.password}<span class="field-error">{errors.password}</span>{/if}
          </div>
          <div class="profile-field">
            <label class="profile-label" for="profile-password-confirm">Confirm Password</label>
            <input
              id="profile-password-confirm"
              class="input"
              class:input-error={errors.passwordConfirm}
              type="password"
              bind:value={formPasswordConfirm}
              oninput={() => { markDirty(); clearError('passwordConfirm'); }}
              onblur={() => validateField('passwordConfirm')}
              placeholder="Re-enter password"
              autocomplete="new-password"
            />
            {#if errors.passwordConfirm}<span class="field-error">{errors.passwordConfirm}</span>{/if}
          </div>
          <button class="btn btn-ghost" onclick={() => { showPasswordChange = false; formPassword = ''; formPasswordConfirm = ''; }}>
            Cancel password change
          </button>
        {/if}
      </div>

      <!-- Security / 2FA (own profile only) -->
      {#if isOwnProfile}
        <div class="profile-section">
          <h3 class="profile-section-title">Security</h3>

          {#if twoFaError}
            <div class="twofa-error">{twoFaError}</div>
          {/if}

          {#if twoFaView === 'idle'}
            <p class="twofa-desc">Add an extra layer of security to your account with two-factor authentication.</p>
            <button class="btn btn-secondary" onclick={startTwoFaSetup} disabled={twoFaLoading}>
              {twoFaLoading ? 'Setting up...' : 'Enable two-factor authentication'}
            </button>

          {:else if twoFaView === 'setup'}
            <p class="twofa-desc">Scan this QR code with your authenticator app (Google Authenticator, Authy, 1Password, etc.).</p>
            <div class="twofa-qr">
              {#if twoFaQrDataUrl}
                <img src={twoFaQrDataUrl} alt="TOTP QR code" width="200" height="200" />
              {/if}
            </div>
            <div class="twofa-secret">
              <span class="profile-label">Manual entry code</span>
              <code class="twofa-secret-code">{twoFaSecret}</code>
            </div>
            <div class="profile-field" style="margin-top: var(--space-lg);">
              <label class="profile-label" for="twofa-verify">Enter the 6-digit code to verify</label>
              <input
                id="twofa-verify"
                class="input twofa-code-input"
                type="text"
                inputmode="numeric"
                maxlength="6"
                bind:value={twoFaCode}
                placeholder="000000"
                autocomplete="one-time-code"
              />
            </div>
            <div class="twofa-actions">
              <button class="btn btn-primary" onclick={confirmTwoFaEnable} disabled={twoFaLoading || twoFaCode.length < 6}>
                {twoFaLoading ? 'Verifying...' : 'Verify and enable'}
              </button>
              <button class="btn btn-ghost" onclick={() => { twoFaView = 'idle'; twoFaError = ''; }}>Cancel</button>
            </div>

          {:else if twoFaView === 'backup_codes'}
            <p class="twofa-desc">Save these backup codes in a safe place. Each code can only be used once. You'll need them if you lose access to your authenticator app.</p>
            <div class="twofa-codes-grid">
              {#each twoFaBackupCodes as code}
                <code class="twofa-backup-code">{code}</code>
              {/each}
            </div>
            <div class="twofa-actions" style="margin-top: var(--space-lg);">
              <button class="btn btn-secondary" onclick={copyBackupCodes}>Copy codes</button>
            </div>
            <label class="twofa-checkbox" style="margin-top: var(--space-md);">
              <input type="checkbox" bind:checked={twoFaCodesSaved} />
              <span>I have saved these backup codes</span>
            </label>
            <div class="twofa-actions" style="margin-top: var(--space-md);">
              <button class="btn btn-primary" onclick={finishBackupCodes} disabled={!twoFaCodesSaved}>Done</button>
            </div>

          {:else if twoFaView === 'enabled'}
            <div class="twofa-status">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
              <span>Two-factor authentication is enabled</span>
            </div>
            <p class="twofa-desc" style="margin-top: var(--space-sm);">{twoFaBackupCodesRemaining} backup code{twoFaBackupCodesRemaining !== 1 ? 's' : ''} remaining</p>
            <div class="twofa-link-group">
              <button class="link-btn-profile" onclick={startRegenerate}>Regenerate backup codes</button>
              <button class="link-btn-profile twofa-danger-link" onclick={startDisable}>Disable two-factor authentication</button>
            </div>

          {:else if twoFaView === 'disable_confirm'}
            <p class="twofa-desc">Enter your password to disable two-factor authentication.</p>
            <div class="profile-field">
              <label class="profile-label" for="twofa-disable-pw">Password</label>
              <input
                id="twofa-disable-pw"
                class="input"
                type="password"
                bind:value={twoFaPassword}
                placeholder="Enter your password"
                autocomplete="current-password"
              />
            </div>
            <div class="twofa-actions">
              <button class="btn btn-primary" onclick={confirmDisable} disabled={twoFaLoading || !twoFaPassword}>
                {twoFaLoading ? 'Disabling...' : 'Disable 2FA'}
              </button>
              <button class="btn btn-ghost" onclick={() => { twoFaView = 'enabled'; twoFaError = ''; }}>Cancel</button>
            </div>

          {:else if twoFaView === 'regenerate_confirm'}
            <p class="twofa-desc">Enter your password to regenerate backup codes. This will invalidate your existing codes.</p>
            <div class="profile-field">
              <label class="profile-label" for="twofa-regen-pw">Password</label>
              <input
                id="twofa-regen-pw"
                class="input"
                type="password"
                bind:value={twoFaPassword}
                placeholder="Enter your password"
                autocomplete="current-password"
              />
            </div>
            <div class="twofa-actions">
              <button class="btn btn-primary" onclick={confirmRegenerate} disabled={twoFaLoading || !twoFaPassword}>
                {twoFaLoading ? 'Regenerating...' : 'Regenerate codes'}
              </button>
              <button class="btn btn-ghost" onclick={() => { twoFaView = 'enabled'; twoFaError = ''; }}>Cancel</button>
            </div>
          {/if}
        </div>
      {/if}

      <div class="profile-section profile-meta">
        <div class="profile-meta-row">
          <span class="profile-meta-label">Member since</span>
          <span class="profile-meta-value">{profileUser.created_at ? new Date(profileUser.created_at).toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' }) : '—'}</span>
        </div>
        <div class="profile-meta-row">
          <span class="profile-meta-label">Last login</span>
          <span class="profile-meta-value">{profileUser.last_login ? new Date(profileUser.last_login).toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' }) : 'Never'}</span>
        </div>
      </div>
    </div>
  </div>
{:else}
  <div class="empty-state">
    <div class="empty-state-title">User not found</div>
    <button class="btn btn-secondary" onclick={goBack}>Back to Team</button>
  </div>
{/if}

<style>
  .profile-page {
    max-width: var(--content-width-narrow);
  }

  .profile-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: var(--space-2xl);
  }

  .profile-header-actions {
    display: flex;
    align-items: center;
    gap: var(--space-md);
  }

  .profile-hero {
    display: flex;
    flex-direction: column;
    align-items: center;
    margin-bottom: var(--space-3xl);
  }

  .profile-avatar-wrapper {
    position: relative;
    width: 96px;
    height: 96px;
    border-radius: 50%;
    overflow: visible;
  }

  .profile-avatar-img {
    width: 96px;
    height: 96px;
    border-radius: 50%;
    object-fit: cover;
    border: 3px solid var(--bg-card);
    box-shadow: var(--shadow-md);
  }

  .profile-avatar-fallback {
    width: 96px;
    height: 96px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 36px;
    font-weight: 700;
    font-family: var(--font-serif);
    border: 3px solid var(--bg-card);
    box-shadow: var(--shadow-md);
  }

  .profile-avatar-upload {
    position: absolute;
    bottom: 0;
    right: 0;
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: var(--accent);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    border: 2px solid var(--bg-card);
    transition: background var(--transition-fast);
  }

  .profile-avatar-upload:hover {
    background: var(--accent-hover);
  }

  .profile-avatar-remove {
    background: none;
    border: none;
    color: var(--text-tertiary);
    font-size: var(--font-size-xs);
    cursor: pointer;
    margin-top: var(--space-sm);
    padding: var(--space-xs) var(--space-sm);
    border-radius: var(--radius-sm);
    transition: color var(--transition-fast);
  }

  .profile-avatar-remove:hover {
    color: var(--danger);
  }

  .profile-form {
    display: flex;
    flex-direction: column;
    gap: var(--space-2xl);
  }

  .profile-section {
    background: var(--bg-card);
    border: 1px solid var(--border-primary);
    border-radius: var(--radius-lg);
    padding: var(--space-xl);
  }

  .profile-section-title {
    font-family: var(--font-serif);
    font-size: 18px;
    font-weight: 600;
    color: var(--text-primary);
    margin-bottom: var(--space-xl);
  }

  .profile-field {
    margin-bottom: var(--space-lg);
  }

  .profile-field:last-child {
    margin-bottom: 0;
  }

  .profile-label {
    display: block;
    font-size: var(--font-size-sm);
    font-weight: 500;
    color: var(--text-secondary);
    margin-bottom: var(--space-xs);
  }

  .profile-hint {
    font-size: var(--font-size-xs);
    color: var(--text-tertiary);
    margin-top: 4px;
    display: block;
  }

  .profile-meta {
    background: transparent;
    border: none;
    padding: var(--space-lg) 0;
  }

  .profile-meta-row {
    display: flex;
    justify-content: space-between;
    padding: var(--space-sm) 0;
    border-bottom: 1px solid var(--border-secondary);
  }

  .profile-meta-row:last-child {
    border-bottom: none;
  }

  .profile-meta-label {
    font-size: var(--font-size-sm);
    color: var(--text-tertiary);
  }

  .profile-meta-value {
    font-size: var(--font-size-sm);
    color: var(--text-secondary);
    font-weight: 500;
  }

  /* 2FA styles */
  .twofa-desc {
    font-size: var(--font-size-sm);
    color: var(--text-secondary);
    line-height: 1.5;
    margin-bottom: var(--space-lg);
  }

  .twofa-error {
    font-size: 13px;
    color: var(--danger, #dc2626);
    background: #fef2f2;
    border: 1px solid #fecaca;
    border-radius: 6px;
    padding: 10px 12px;
    margin-bottom: var(--space-lg);
  }

  .twofa-qr {
    display: flex;
    justify-content: center;
    margin-bottom: var(--space-lg);
  }

  .twofa-qr img {
    border-radius: var(--radius-md);
    border: 1px solid var(--border-primary);
  }

  .twofa-secret {
    text-align: center;
    margin-bottom: var(--space-sm);
  }

  .twofa-secret-code {
    display: block;
    font-family: var(--font-mono, monospace);
    font-size: 13px;
    color: var(--text-primary);
    background: var(--bg-secondary);
    padding: 8px 12px;
    border-radius: var(--radius-sm);
    margin-top: 4px;
    letter-spacing: 0.05em;
    word-break: break-all;
    user-select: all;
  }

  .twofa-code-input {
    font-family: var(--font-mono, monospace);
    font-size: 20px;
    text-align: center;
    letter-spacing: 0.3em;
  }

  .twofa-actions {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
  }

  .twofa-codes-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: var(--space-xs);
  }

  .twofa-backup-code {
    font-family: var(--font-mono, monospace);
    font-size: 13px;
    padding: 6px 10px;
    background: var(--bg-secondary);
    border-radius: var(--radius-sm);
    text-align: center;
    user-select: all;
  }

  .twofa-checkbox {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
    font-size: var(--font-size-sm);
    color: var(--text-secondary);
    cursor: pointer;
  }

  .twofa-checkbox input[type="checkbox"] {
    width: 16px;
    height: 16px;
    cursor: pointer;
  }

  .twofa-status {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
    font-size: var(--font-size-sm);
    font-weight: 500;
    color: #16a34a;
  }

  .twofa-status svg {
    stroke: #16a34a;
  }

  .twofa-link-group {
    display: flex;
    flex-direction: column;
    gap: var(--space-sm);
    margin-top: var(--space-lg);
  }

  .link-btn-profile {
    background: none;
    border: none;
    padding: 0;
    font-size: var(--font-size-sm);
    color: var(--text-secondary);
    cursor: pointer;
    text-decoration: underline;
    text-underline-offset: 2px;
    text-align: left;
  }

  .link-btn-profile:hover {
    color: var(--text-primary);
  }

  .twofa-danger-link:hover {
    color: var(--danger, #dc2626);
  }

  @media (max-width: 768px) {
    .profile-header {
      flex-wrap: wrap;
      gap: var(--space-md);
    }

    .profile-header-actions {
      width: 100%;
    }

    .twofa-codes-grid {
      grid-template-columns: 1fr;
    }

    .twofa-actions {
      flex-wrap: wrap;
    }
  }
</style>
