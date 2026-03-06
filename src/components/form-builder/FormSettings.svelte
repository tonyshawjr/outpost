<script>
  let { settings = {}, formSlug = '', onChange } = $props();

  function update(key, value) {
    onChange({ ...settings, [key]: value });
  }
</script>

<div class="form-settings">
  <div class="settings-section">
    <div class="settings-section-title">General</div>

    <div class="settings-field">
      <label class="settings-label">Submit Button Label</label>
      <input type="text" class="settings-input" value={settings.submit_label || 'Submit'} oninput={(e) => update('submit_label', e.target.value)} />
    </div>

    <div class="settings-field">
      <label class="settings-checkbox-label">
        <input type="checkbox" checked={settings.honeypot !== false} onchange={(e) => update('honeypot', e.target.checked)} />
        Honeypot spam protection
      </label>
      <p class="settings-hint">Adds an invisible field that traps bots. No user impact.</p>
    </div>
  </div>

  <div class="settings-section">
    <div class="settings-section-title">Confirmation</div>

    <div class="settings-field">
      <label class="settings-label">Type</label>
      <select class="settings-input" value={settings.confirmation_type || 'message'} onchange={(e) => update('confirmation_type', e.target.value)}>
        <option value="message">Show message</option>
        <option value="redirect">Redirect to URL</option>
      </select>
    </div>

    {#if (settings.confirmation_type || 'message') === 'message'}
      <div class="settings-field">
        <label class="settings-label">Confirmation Message</label>
        <textarea class="settings-input settings-textarea" value={settings.confirmation_message || ''} oninput={(e) => update('confirmation_message', e.target.value)} placeholder="Thank you! Your submission has been received."></textarea>
      </div>
    {:else}
      <div class="settings-field">
        <label class="settings-label">Redirect URL</label>
        <input type="text" class="settings-input" value={settings.redirect_url || ''} oninput={(e) => update('redirect_url', e.target.value)} placeholder="/thank-you" />
      </div>
    {/if}
  </div>

  <div class="settings-section">
    <div class="settings-section-title">Template Usage</div>
    <div class="settings-code">
      <code>{`{% form '${formSlug}' %}`}</code>
    </div>
    <p class="settings-hint">Add this tag to any theme template to render this form.</p>
  </div>
</div>

<style>
  .form-settings {
    padding: 24px;
    max-width: 600px;
    margin: 0 auto;
  }

  .settings-section {
    margin-bottom: 24px;
    padding-bottom: 24px;
    border-bottom: 1px solid var(--border-color, #e5e7eb);
  }

  .settings-section:last-child {
    border-bottom: none;
  }

  .settings-section-title {
    font-size: 13px;
    font-weight: 600;
    color: var(--text-primary);
    margin-bottom: 12px;
  }

  .settings-field {
    margin-bottom: 12px;
  }

  .settings-label {
    display: block;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--text-tertiary);
    font-weight: 500;
    margin-bottom: 4px;
  }

  .settings-input {
    width: 100%;
    padding: 6px 8px;
    font-size: 13px;
    border: 1px solid transparent;
    border-radius: var(--radius-md, 6px);
    background: var(--input-bg, #fff);
    color: var(--text-primary);
    transition: border-color 0.15s;
  }

  .settings-input:hover {
    border-color: var(--border-color, #e5e7eb);
  }

  .settings-input:focus {
    border-color: var(--accent-color, #2563eb);
    outline: none;
  }

  .settings-textarea {
    min-height: 80px;
    resize: vertical;
  }

  .settings-checkbox-label {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 13px;
    color: var(--text-primary);
    cursor: pointer;
  }

  .settings-hint {
    font-size: 11px;
    color: var(--text-tertiary);
    margin-top: 4px;
    line-height: 1.4;
  }

  .settings-code {
    background: var(--bg-subtle, #f9fafb);
    border: 1px solid var(--border-color, #e5e7eb);
    border-radius: var(--radius-md, 6px);
    padding: 10px 14px;
    font-family: var(--font-mono, monospace);
    font-size: 13px;
    color: var(--text-primary);
    margin-bottom: 4px;
  }
</style>
