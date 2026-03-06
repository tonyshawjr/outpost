<script>
  import { auth, setCsrfToken } from '$lib/api.js';
  import { user } from '$lib/stores.js';
  import { minLength, match } from '$lib/validation.js';
  import outpostLogo from '../assets/outpost.svg';

  // view: 'login' | 'forgot' | 'reset' | 'forgot_sent' | 'totp'
  let view = $state('login');

  let username    = $state('');
  let password    = $state('');
  let email       = $state('');
  let newPassword = $state('');
  let newPassword2 = $state('');
  let resetToken  = $state('');

  // TOTP state
  let totpCode   = $state('');
  let totpToken  = $state('');
  let useBackupCode = $state(false);

  let error      = $state('');
  let submitting = $state(false);
  let message    = $state('');
  let fieldErrors = $state({});

  // Detect ?reset_token= in URL on mount
  $effect(() => {
    const params = new URLSearchParams(window.location.search);
    const t = params.get('reset_token');
    if (t) {
      resetToken = t;
      view = 'reset';
      // Clean the token out of the URL so it doesn't sit in history
      const clean = window.location.pathname + window.location.hash;
      window.history.replaceState(null, '', clean);
    }
  });

  async function handleLogin(e) {
    e.preventDefault();
    error = '';
    submitting = true;
    try {
      const data = await auth.login(username, password);
      if (data.success) {
        if (data.requires_2fa) {
          totpToken = data.totp_token;
          totpCode = '';
          useBackupCode = false;
          view = 'totp';
        } else {
          setCsrfToken(data.csrf_token);
          try {
            const me = await auth.me();
            user.set(me.user ?? data.user);
            if (me.csrf_token) setCsrfToken(me.csrf_token);
          } catch {
            user.set(data.user);
          }
        }
      }
    } catch (err) {
      error = err.message || 'Login failed';
    } finally {
      submitting = false;
    }
  }

  async function handleTotpVerify(e) {
    e?.preventDefault();
    error = '';
    submitting = true;
    try {
      const data = await auth.totpVerify(totpCode, totpToken, useBackupCode);
      if (data.success) {
        setCsrfToken(data.csrf_token);
        try {
          const me = await auth.me();
          user.set(me.user ?? data.user);
          if (me.csrf_token) setCsrfToken(me.csrf_token);
        } catch {
          user.set(data.user);
        }
      }
    } catch (err) {
      error = err.message || 'Verification failed';
    } finally {
      submitting = false;
    }
  }

  function handleTotpInput(e) {
    const val = e.target.value.replace(/\D/g, '').slice(0, 6);
    totpCode = val;
    // Auto-submit on 6 digits (not backup codes)
    if (!useBackupCode && val.length === 6) {
      handleTotpVerify();
    }
  }

  async function handleForgot(e) {
    e.preventDefault();
    error = '';
    submitting = true;
    try {
      await auth.forgot(email);
      view = 'forgot_sent';
    } catch (err) {
      error = err.message || 'Something went wrong';
    } finally {
      submitting = false;
    }
  }

  function validateResetField(field) {
    const rules = {
      newPassword: minLength(newPassword, 8, 'Password'),
      newPassword2: match(newPassword, newPassword2),
    };
    if (field) {
      if (rules[field]) fieldErrors = { ...fieldErrors, [field]: rules[field] };
      else { const { [field]: _, ...rest } = fieldErrors; fieldErrors = rest; }
    } else {
      fieldErrors = {};
      for (const [k, msg] of Object.entries(rules)) { if (msg) fieldErrors[k] = msg; }
    }
    return Object.keys(fieldErrors).length === 0;
  }

  async function handleReset(e) {
    e.preventDefault();
    error = '';
    if (!validateResetField()) return;
    submitting = true;
    try {
      await auth.reset(resetToken, newPassword);
      view = 'login';
      message = 'Password updated — please sign in.';
    } catch (err) {
      error = err.message || 'Reset failed';
    } finally {
      submitting = false;
    }
  }
</script>

<div class="login-page">
  <div class="login-card">
    <div class="login-logo">
      <img src={outpostLogo} alt="Outpost" style="height: 20px; width: auto;" />
      <div style="font-size: 0.8rem; color: var(--text-tertiary); margin-top: 4px;">Content Management</div>
    </div>

    {#if message}
      <div class="login-success">{message}</div>
    {/if}

    {#if error}
      <div class="login-error">{error}</div>
    {/if}

    {#if view === 'login'}
      <form onsubmit={handleLogin}>
        <div class="form-group">
          <label class="form-label" for="username">Username</label>
          <input
            id="username"
            class="input"
            type="text"
            bind:value={username}
            required
            autocomplete="username"
            disabled={submitting}
          />
        </div>

        <div class="form-group">
          <label class="form-label" for="password">Password</label>
          <input
            id="password"
            class="input"
            type="password"
            bind:value={password}
            required
            autocomplete="current-password"
            disabled={submitting}
          />
        </div>

        <button class="btn btn-primary" type="submit" disabled={submitting} style="width: 100%; height: 40px;">
          {#if submitting}
            <div class="spinner" style="width: 16px; height: 16px; border-width: 2px;"></div>
          {:else}
            Sign in
          {/if}
        </button>
      </form>

      <div class="login-footer-link">
        <button
          type="button"
          class="link-btn"
          onclick={() => { view = 'forgot'; error = ''; message = ''; }}
        >Forgot password?</button>
      </div>

    {:else if view === 'totp'}
      <p class="login-subtitle">
        {#if useBackupCode}
          Enter one of your backup codes.
        {:else}
          Enter the 6-digit code from your authenticator app.
        {/if}
      </p>
      <form onsubmit={handleTotpVerify}>
        {#if useBackupCode}
          <div class="form-group">
            <label class="form-label" for="backup-code">Backup code</label>
            <input
              id="backup-code"
              class="input totp-input"
              type="text"
              bind:value={totpCode}
              placeholder="e.g. abcd234567"
              autocomplete="off"
              disabled={submitting}
            />
          </div>
        {:else}
          <div class="form-group">
            <label class="form-label" for="totp-code">Authentication code</label>
            <input
              id="totp-code"
              class="input totp-input"
              type="text"
              inputmode="numeric"
              maxlength="6"
              value={totpCode}
              oninput={handleTotpInput}
              placeholder="000000"
              autocomplete="one-time-code"
              disabled={submitting}
            />
          </div>
        {/if}

        <button class="btn btn-primary" type="submit" disabled={submitting || (!useBackupCode && totpCode.length < 6)} style="width: 100%; height: 40px;">
          {#if submitting}
            <div class="spinner" style="width: 16px; height: 16px; border-width: 2px;"></div>
          {:else}
            Verify
          {/if}
        </button>
      </form>

      <div class="login-footer-link">
        <button type="button" class="link-btn" onclick={() => { useBackupCode = !useBackupCode; totpCode = ''; error = ''; }}>
          {useBackupCode ? 'Use authenticator app' : 'Use a backup code'}
        </button>
      </div>
      <div class="login-footer-link" style="margin-top: 8px;">
        <button type="button" class="link-btn" onclick={() => { view = 'login'; error = ''; totpCode = ''; totpToken = ''; }}>
          ← Back to sign in
        </button>
      </div>

    {:else if view === 'forgot'}
      <p class="login-subtitle">Enter your email and we'll send you a reset link.</p>
      <form onsubmit={handleForgot}>
        <div class="form-group">
          <label class="form-label" for="reset-email">Email address</label>
          <input
            id="reset-email"
            class="input"
            type="email"
            bind:value={email}
            required
            autocomplete="email"
            disabled={submitting}
          />
        </div>

        <button class="btn btn-primary" type="submit" disabled={submitting} style="width: 100%; height: 40px;">
          {#if submitting}
            <div class="spinner" style="width: 16px; height: 16px; border-width: 2px;"></div>
          {:else}
            Send reset link
          {/if}
        </button>
      </form>

      <div class="login-footer-link">
        <button type="button" class="link-btn" onclick={() => { view = 'login'; error = ''; }}>
          ← Back to sign in
        </button>
      </div>

    {:else if view === 'forgot_sent'}
      <div class="login-info">
        Check your inbox — if that address is on an account you'll receive a reset link shortly.
      </div>
      <div class="login-footer-link" style="margin-top: 16px;">
        <button type="button" class="link-btn" onclick={() => { view = 'login'; error = ''; }}>
          ← Back to sign in
        </button>
      </div>

    {:else if view === 'reset'}
      <p class="login-subtitle">Choose a new password.</p>
      <form onsubmit={handleReset}>
        <div class="form-group">
          <label class="form-label" for="new-password">New password</label>
          <input
            id="new-password"
            class="input"
            class:input-error={fieldErrors.newPassword}
            type="password"
            bind:value={newPassword}
            required
            minlength="8"
            autocomplete="new-password"
            disabled={submitting}
            oninput={() => { const { newPassword: _, ...rest } = fieldErrors; fieldErrors = rest; }}
            onblur={() => validateResetField('newPassword')}
          />
          {#if fieldErrors.newPassword}<span class="field-error">{fieldErrors.newPassword}</span>{/if}
        </div>

        <div class="form-group">
          <label class="form-label" for="new-password2">Confirm password</label>
          <input
            id="new-password2"
            class="input"
            class:input-error={fieldErrors.newPassword2}
            type="password"
            bind:value={newPassword2}
            required
            minlength="8"
            autocomplete="new-password"
            disabled={submitting}
            oninput={() => { const { newPassword2: _, ...rest } = fieldErrors; fieldErrors = rest; }}
            onblur={() => validateResetField('newPassword2')}
          />
          {#if fieldErrors.newPassword2}<span class="field-error">{fieldErrors.newPassword2}</span>{/if}
        </div>

        <button class="btn btn-primary" type="submit" disabled={submitting} style="width: 100%; height: 40px;">
          {#if submitting}
            <div class="spinner" style="width: 16px; height: 16px; border-width: 2px;"></div>
          {:else}
            Set new password
          {/if}
        </button>
      </form>
    {/if}

  </div>
</div>

<style>
  .login-page {
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 24px;
    background: var(--bg);
  }

  .login-card {
    width: 100%;
    max-width: 360px;
  }

  .login-logo {
    text-align: center;
    margin-bottom: 32px;
  }

  .login-subtitle {
    font-size: 13px;
    color: var(--text-secondary);
    margin-bottom: 20px;
  }

  .login-error {
    font-size: 13px;
    color: var(--danger, #dc2626);
    background: #fef2f2;
    border: 1px solid #fecaca;
    border-radius: 6px;
    padding: 10px 12px;
    margin-bottom: 16px;
  }

  .login-success {
    font-size: 13px;
    color: #16a34a;
    background: #f0fdf4;
    border: 1px solid #bbf7d0;
    border-radius: 6px;
    padding: 10px 12px;
    margin-bottom: 16px;
  }

  .login-info {
    font-size: 13px;
    color: var(--text-secondary);
    background: var(--bg-secondary, #f5f5f5);
    border-radius: 6px;
    padding: 12px 14px;
    line-height: 1.5;
  }

  .login-footer-link {
    text-align: center;
    margin-top: 16px;
  }

  .link-btn {
    background: none;
    border: none;
    padding: 0;
    font-size: 13px;
    color: var(--text-secondary);
    cursor: pointer;
    text-decoration: underline;
    text-underline-offset: 2px;
  }

  .link-btn:hover {
    color: var(--text-primary, #1a1a1a);
  }

  .totp-input {
    font-family: var(--font-mono, monospace);
    font-size: 20px;
    text-align: center;
    letter-spacing: 0.3em;
  }
</style>
