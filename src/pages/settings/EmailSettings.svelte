<script>
  import { forms as formsApi } from '$lib/api.js';
  import { addToast } from '$lib/stores.js';

  let { settings = {}, onSettingChange = () => {} } = $props();

  let testingSmtp = $state(false);

  async function testSmtp() {
    testingSmtp = true;
    try {
      await formsApi.testSmtp(settings);
      addToast('Test email sent — check your inbox', 'success');
    } catch (err) {
      addToast('SMTP test failed: ' + err.message, 'error');
    } finally {
      testingSmtp = false;
    }
  }
</script>

<div class="settings-section">
  <h3 class="settings-section-title">Email</h3>
  <p class="settings-section-desc">
    Configure SMTP to send form submission notifications. Leave blank to use PHP's built-in <code>mail()</code> function.
  </p>

  <div class="form-group">
    <label class="form-label">Notification Address</label>
    <input
      class="input"
      type="email"
      placeholder="admin@example.com"
      value={settings.notify_email || ''}
      oninput={(e) => onSettingChange('notify_email', e.target.value)}
    />
    <p class="form-hint">Where form submissions are emailed.</p>
  </div>
  <div class="form-row">
    <div class="form-group">
      <label class="form-label">From Name</label>
      <input class="input" type="text" placeholder="My Site" value={settings.from_name || ''} oninput={(e) => onSettingChange('from_name', e.target.value)} />
    </div>
    <div class="form-group">
      <label class="form-label">From Email</label>
      <input class="input" type="email" placeholder="noreply@example.com" value={settings.from_email || ''} oninput={(e) => onSettingChange('from_email', e.target.value)} />
    </div>
  </div>
  <div class="form-row">
    <div class="form-group" style="flex: 2;">
      <label class="form-label">SMTP Host</label>
      <input class="input" type="text" placeholder="smtp.mailgun.org" value={settings.smtp_host || ''} oninput={(e) => onSettingChange('smtp_host', e.target.value)} />
    </div>
    <div class="form-group" style="flex: 1;">
      <label class="form-label">Port</label>
      <input class="input" type="number" placeholder="587" value={settings.smtp_port || ''} oninput={(e) => onSettingChange('smtp_port', e.target.value)} />
    </div>
    <div class="form-group" style="flex: 1;">
      <label class="form-label">Encryption</label>
      <select class="input" value={settings.smtp_encryption || 'tls'} onchange={(e) => onSettingChange('smtp_encryption', e.target.value)}>
        <option value="tls">TLS (STARTTLS)</option>
        <option value="ssl">SSL</option>
        <option value="none">None</option>
      </select>
    </div>
  </div>
  <div class="form-row">
    <div class="form-group">
      <label class="form-label">SMTP Username</label>
      <input class="input" type="text" placeholder="apikey" value={settings.smtp_username || ''} oninput={(e) => onSettingChange('smtp_username', e.target.value)} />
    </div>
    <div class="form-group">
      <label class="form-label">SMTP Password</label>
      <input class="input" type="password" placeholder="••••••••" value={settings.smtp_password || ''} oninput={(e) => onSettingChange('smtp_password', e.target.value)} />
    </div>
  </div>
  <div style="margin-top: var(--space-md);">
    <button class="btn btn-secondary" onclick={testSmtp} disabled={testingSmtp} type="button">
      {testingSmtp ? 'Sending…' : 'Send Test Email'}
    </button>
    {#if !settings.notify_email}
      <p class="form-hint" style="margin-top: var(--space-xs); color: var(--warning);">Set a Notification Address above before testing.</p>
    {/if}
  </div>
</div>

<style>
  .form-row {
    display: flex;
    gap: var(--space-md);
  }
  .form-row .form-group {
    flex: 1;
    min-width: 0;
  }
  .form-hint {
    font-size: var(--font-size-xs);
    color: var(--dim);
    margin-top: var(--space-xs);
  }
  .form-hint code {
    font-family: var(--font-mono);
    background: var(--hover);
    padding: 1px 4px;
    border-radius: 3px;
  }
  .settings-section-desc code {
    font-family: var(--font-mono);
    background: var(--hover);
    padding: 1px 4px;
    border-radius: 3px;
  }

  @media (max-width: 480px) {
    .form-row {
      flex-wrap: wrap;
    }
  }
</style>
