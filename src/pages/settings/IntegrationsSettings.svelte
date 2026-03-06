<script>
  import { onMount } from 'svelte';
  import { webhooks as whApi, apikeys as apikeysApi } from '$lib/api.js';
  import { addToast } from '$lib/stores.js';

  let { settings = {}, onSettingChange = () => {} } = $props();

  // API Keys
  let apiKeysList = $state([]);
  let apiKeysLoading = $state(true);
  let newKeyName = $state('');
  let creatingKey = $state(false);
  let newKeyValue = $state('');
  let confirmRevokeId = $state(null);

  // Webhooks
  let webhookList = $state([]);
  let webhooksLoading = $state(true);
  let showModal = $state(false);
  let editing = $state(null);
  let form = $state({ name: '', url: '', events: ['*'], headers: '', active: true });
  let saving = $state(false);
  let newSecret = $state('');
  let showDeliveries = $state(null);
  let deliveries = $state([]);
  let deliveriesLoading = $state(false);
  let confirmDeleteId = $state(null);
  let confirmRegenId = $state(null);
  let testingId = $state(null);

  const allEvents = [
    { group: 'Entries', events: ['entry.created', 'entry.updated', 'entry.published', 'entry.unpublished', 'entry.deleted'] },
    { group: 'Pages', events: ['page.updated', 'page.published', 'page.unpublished', 'page.deleted'] },
    { group: 'Media', events: ['media.created', 'media.deleted'] },
    { group: 'Members', events: ['member.created', 'member.updated', 'member.deleted'] },
    { group: 'Forms', events: ['form.submitted'] },
    { group: 'System', events: ['cache.cleared'] },
  ];

  let isWildcard = $derived(form.events.includes('*'));

  onMount(async () => {
    // Load API keys
    try {
      const data = await apikeysApi.list();
      apiKeysList = data.keys || [];
    } catch (_) {}
    apiKeysLoading = false;

    // Load webhooks
    try {
      const data = await whApi.list();
      webhookList = data.webhooks || [];
    } catch (_) {}
    webhooksLoading = false;
  });

  // --- API Keys ---
  async function createApiKey() {
    if (!newKeyName.trim()) return;
    creatingKey = true;
    try {
      const data = await apikeysApi.create({ name: newKeyName.trim() });
      newKeyValue = data.key;
      newKeyName = '';
      const list = await apikeysApi.list();
      apiKeysList = list.keys || [];
      addToast('API key created', 'success');
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      creatingKey = false;
    }
  }

  async function revokeApiKey(id) {
    if (confirmRevokeId !== id) { confirmRevokeId = id; return; }
    confirmRevokeId = null;
    try {
      await apikeysApi.revoke(id);
      apiKeysList = apiKeysList.filter(k => k.id !== id);
      addToast('API key revoked', 'success');
    } catch (err) {
      addToast(err.message, 'error');
    }
  }

  function copyApiKey() {
    navigator.clipboard.writeText(newKeyValue).then(() => addToast('Key copied', 'success'));
  }

  // --- Webhooks ---
  async function loadWebhooks() {
    try {
      const data = await whApi.list();
      webhookList = data.webhooks || [];
    } catch (err) {
      addToast(err.message, 'error');
    }
  }

  function openCreate() {
    editing = null;
    form = { name: '', url: '', events: ['*'], headers: '', active: true };
    newSecret = '';
    showModal = true;
  }

  async function openEdit(wh) {
    try {
      const data = await whApi.get(wh.id);
      const w = data.webhook;
      editing = w;
      const hdrs = w.headers && typeof w.headers === 'object' && Object.keys(w.headers).length
        ? Object.entries(w.headers).map(([k, v]) => `${k}: ${v}`).join('\n')
        : '';
      form = { name: w.name, url: w.url, events: [...w.events], headers: hdrs, active: !!w.active };
      newSecret = '';
      showModal = true;
    } catch (err) {
      addToast(err.message, 'error');
    }
  }

  function toggleEvent(evt) {
    if (form.events.includes('*')) { form.events = [evt]; return; }
    if (form.events.includes(evt)) {
      form.events = form.events.filter(e => e !== evt);
    } else {
      form.events = [...form.events, evt];
    }
  }

  function toggleWildcard() {
    form.events = form.events.includes('*') ? [] : ['*'];
  }

  function parseHeaders(text) {
    if (!text.trim()) return {};
    const result = {};
    for (const line of text.split('\n')) {
      const idx = line.indexOf(':');
      if (idx > 0) result[line.substring(0, idx).trim()] = line.substring(idx + 1).trim();
    }
    return result;
  }

  async function handleSave() {
    if (!form.url.trim()) { addToast('URL is required', 'error'); return; }
    if (form.events.length === 0) { addToast('Select at least one event', 'error'); return; }
    saving = true;
    try {
      const payload = {
        name: form.name.trim(), url: form.url.trim(),
        events: form.events, headers: parseHeaders(form.headers), active: form.active,
      };
      if (editing) {
        await whApi.update(editing.id, payload);
        addToast('Webhook updated', 'success');
      } else {
        const result = await whApi.create(payload);
        if (result.secret) {
          newSecret = result.secret;
          addToast('Webhook created — copy your secret now', 'success');
          await loadWebhooks();
          return;
        }
        addToast('Webhook created', 'success');
      }
      showModal = false;
      await loadWebhooks();
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      saving = false;
    }
  }

  async function handleDelete(id) {
    try {
      await whApi.delete(id);
      addToast('Webhook deleted', 'success');
      confirmDeleteId = null;
      await loadWebhooks();
    } catch (err) {
      addToast(err.message, 'error');
    }
  }

  async function handleToggle(wh) {
    try {
      await whApi.update(wh.id, { active: !wh.active });
      wh.active = !wh.active;
      webhookList = [...webhookList];
    } catch (err) {
      addToast(err.message, 'error');
    }
  }

  async function handleTest(wh) {
    testingId = wh.id;
    try {
      const result = await whApi.test(wh.id);
      if (result.success) addToast(`Test delivered (${result.status_code})`, 'success');
      else addToast(`Test failed: ${result.status_code || 'no response'}`, 'error');
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      testingId = null;
    }
  }

  async function handleRegenerate(id) {
    try {
      const result = await whApi.regenerateSecret(id);
      newSecret = result.secret;
      confirmRegenId = null;
      addToast('Secret regenerated — copy it now', 'success');
    } catch (err) {
      addToast(err.message, 'error');
    }
  }

  async function loadDeliveries(wh) {
    if (showDeliveries === wh.id) { showDeliveries = null; return; }
    showDeliveries = wh.id;
    deliveriesLoading = true;
    try {
      const data = await whApi.deliveries(wh.id);
      deliveries = data.deliveries || [];
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      deliveriesLoading = false;
    }
  }

  function statusColor(status) {
    if (status === 'success') return '#34a853';
    if (status === 'failed') return '#ea4335';
    if (status === 'retrying') return '#f59e0b';
    return '#888';
  }

  function copySecret() {
    navigator.clipboard.writeText(newSecret);
    addToast('Secret copied', 'success');
  }

  function formatDate(d) {
    if (!d) return '';
    return new Date(d + 'Z').toLocaleString();
  }
</script>

<div class="settings-section">
  <h3 class="settings-section-title">Integrations</h3>
  <p class="settings-section-desc">API keys, webhooks, and third-party integrations.</p>

  <!-- reCAPTCHA -->
  <div class="int-block">
    <h4 class="int-block-title">reCAPTCHA</h4>
    <p class="int-block-desc">Optional reCAPTCHA v2 spam protection for forms. Leave blank to disable.</p>
    <div class="form-group">
      <label class="form-label">Site Key</label>
      <input class="input" type="text" placeholder="6Lcxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx" value={settings.recaptcha_site_key || ''} oninput={(e) => onSettingChange('recaptcha_site_key', e.target.value)} />
      <p class="form-hint">Add to your HTML: <code>&lt;div class="g-recaptcha" data-sitekey="YOUR_SITE_KEY"&gt;&lt;/div&gt;</code></p>
    </div>
    <div class="form-group">
      <label class="form-label">Secret Key</label>
      <input class="input" type="password" placeholder="••••••••" value={settings.recaptcha_secret || ''} oninput={(e) => onSettingChange('recaptcha_secret', e.target.value)} />
    </div>
  </div>

  <!-- API Keys -->
  <div class="int-block">
    <h4 class="int-block-title">API Keys</h4>
    <p class="int-block-desc">Create API keys for headless access — CI/CD, static site generators, or external tools. Use <code>Authorization: Bearer &lt;key&gt;</code>.</p>

    {#if apiKeysLoading}
      <p style="font-size: var(--font-size-sm); color: var(--text-tertiary);">Loading…</p>
    {:else}
      {#if newKeyValue}
        <div class="key-banner">
          <label class="form-label" style="font-size: 0.6875rem; text-transform: uppercase; letter-spacing: 0.06em; color: var(--text-tertiary);">New API Key</label>
          <div style="display: flex; align-items: center; gap: var(--space-sm);">
            <code class="key-code">{newKeyValue}</code>
            <button class="btn btn-secondary" style="white-space: nowrap; flex-shrink: 0;" onclick={copyApiKey} type="button">Copy</button>
          </div>
          <p style="font-size: var(--font-size-xs); color: var(--warning); margin-top: var(--space-xs);">Copy this key now — it won't be shown again.</p>
          <button class="btn btn-ghost" style="margin-top: var(--space-sm);" onclick={() => newKeyValue = ''} type="button">Dismiss</button>
        </div>
      {/if}

      {#if apiKeysList.length > 0}
        <div style="margin-bottom: var(--space-lg);">
          {#each apiKeysList as key (key.id)}
            <div class="api-key-row">
              <div style="min-width: 0;">
                <div style="font-size: var(--font-size-sm); font-weight: 500;">{key.name}</div>
                <div style="font-size: var(--font-size-xs); color: var(--text-tertiary); font-family: var(--font-mono);">
                  {key.key_prefix}••••••••
                  <span style="margin-left: var(--space-sm); font-family: var(--font-sans);">{key.display_name || key.username}</span>
                  {#if key.last_used_at}
                    <span style="margin-left: var(--space-sm);">Used {new Date(key.last_used_at).toLocaleDateString()}</span>
                  {:else}
                    <span style="margin-left: var(--space-sm);">Never used</span>
                  {/if}
                </div>
              </div>
              <div style="flex-shrink: 0; margin-left: var(--space-md);">
                {#if confirmRevokeId === key.id}
                  <div style="display: flex; gap: var(--space-xs); align-items: center;">
                    <button class="btn btn-danger" style="font-size: var(--font-size-xs);" onclick={() => revokeApiKey(key.id)} type="button">Revoke</button>
                    <button class="btn btn-ghost" style="font-size: var(--font-size-xs);" onclick={() => confirmRevokeId = null} type="button">Cancel</button>
                  </div>
                {:else}
                  <button class="btn btn-ghost" style="font-size: var(--font-size-xs); color: var(--danger);" onclick={() => revokeApiKey(key.id)} type="button">Revoke</button>
                {/if}
              </div>
            </div>
          {/each}
        </div>
      {/if}

      <div style="display: flex; gap: var(--space-sm); align-items: flex-end;">
        <div class="form-group" style="flex: 1; margin-bottom: 0;">
          <label class="form-label">Key Name</label>
          <input class="input" type="text" placeholder="e.g. CI/CD, Next.js frontend" bind:value={newKeyName} onkeydown={(e) => { if (e.key === 'Enter') createApiKey(); }} />
        </div>
        <button class="btn btn-secondary" onclick={createApiKey} disabled={creatingKey || !newKeyName.trim()} type="button">
          {creatingKey ? 'Creating…' : 'Create Key'}
        </button>
      </div>
    {/if}
  </div>

  <!-- Webhooks -->
  <div class="int-block">
    <div class="int-block-header">
      <div>
        <h4 class="int-block-title">Webhooks</h4>
        <p class="int-block-desc">{webhookList.length} webhook{webhookList.length !== 1 ? 's' : ''} — Send HTTP notifications to external services when content changes.</p>
      </div>
      <button class="btn btn-secondary" onclick={openCreate} type="button">Add webhook</button>
    </div>

    {#if webhooksLoading}
      <p style="font-size: var(--font-size-sm); color: var(--text-tertiary);">Loading…</p>
    {:else if webhookList.length === 0}
      <div style="text-align: center; padding: 32px 0; color: var(--text-tertiary);">
        <p style="font-size: 13px;">No webhooks configured yet.</p>
      </div>
    {:else}
      <div class="webhook-list">
        {#each webhookList as wh (wh.id)}
          <div class="webhook-row" class:inactive={!wh.active}>
            <div class="webhook-info">
              <div class="webhook-name">{wh.name || 'Unnamed webhook'}</div>
              <div class="webhook-url">{wh.url}</div>
              <div class="webhook-meta">
                {#if Array.isArray(wh.events) && wh.events.includes('*')}
                  <span>All events</span>
                {:else if Array.isArray(wh.events)}
                  <span>{wh.events.length} event{wh.events.length !== 1 ? 's' : ''}</span>
                {/if}
              </div>
            </div>
            <div class="webhook-actions">
              <button class="wh-btn" onclick={() => handleTest(wh)} disabled={testingId === wh.id}>{testingId === wh.id ? 'Sending...' : 'Test'}</button>
              <button class="wh-btn" onclick={() => loadDeliveries(wh)}>{showDeliveries === wh.id ? 'Hide log' : 'Log'}</button>
              <button class="wh-btn" onclick={() => openEdit(wh)}>Edit</button>
              <label class="toggle-switch">
                <input type="checkbox" checked={!!wh.active} onchange={() => handleToggle(wh)} />
                <span class="toggle-slider"></span>
              </label>
            </div>
          </div>
          {#if showDeliveries === wh.id}
            <div class="deliveries-panel">
              {#if deliveriesLoading}
                <p style="color: var(--text-tertiary); padding: 12px 0;">Loading deliveries...</p>
              {:else if deliveries.length === 0}
                <p style="color: var(--text-tertiary); padding: 12px 0;">No deliveries yet</p>
              {:else}
                <table class="deliveries-table">
                  <thead><tr><th>Event</th><th>Status</th><th>Code</th><th>Attempts</th><th>Time</th></tr></thead>
                  <tbody>
                    {#each deliveries as d (d.id)}
                      <tr>
                        <td><code>{d.event}</code></td>
                        <td><span class="status-dot" style="background:{statusColor(d.status)}"></span> {d.status}</td>
                        <td>{d.status_code || '—'}</td>
                        <td>{d.attempts}</td>
                        <td>{formatDate(d.created_at)}</td>
                      </tr>
                    {/each}
                  </tbody>
                </table>
              {/if}
            </div>
          {/if}
        {/each}
      </div>
    {/if}
  </div>
</div>

<!-- Webhook Create/Edit Modal -->
{#if showModal}
  <!-- svelte-ignore a11y_no_static_element_interactions -->
  <div class="modal-overlay" onclick={() => { if (!newSecret) showModal = false; }}>
    <!-- svelte-ignore a11y_no_static_element_interactions -->
    <div class="modal" onclick={(e) => e.stopPropagation()}>
      <div class="modal-header">
        <h2 class="modal-title">{editing ? 'Edit webhook' : 'New webhook'}</h2>
        {#if !newSecret}
          <button class="btn btn-ghost btn-sm" onclick={() => showModal = false} aria-label="Close">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
          </button>
        {/if}
      </div>

      {#if newSecret}
        <div class="secret-banner">
          <div class="secret-label">SIGNING SECRET</div>
          <p style="font-size:13px; color:var(--text-tertiary); margin: 0 0 8px;">Copy this secret now. It won't be shown again.</p>
          <div class="secret-value">
            <code>{newSecret}</code>
            <button class="btn btn-secondary" onclick={copySecret} type="button">Copy</button>
          </div>
        </div>
        <div style="display: flex; justify-content: flex-end; margin-top: 16px;">
          <button class="btn btn-primary" onclick={() => { showModal = false; newSecret = ''; }} type="button">Done</button>
        </div>
      {:else}
        <div style="padding: var(--space-lg);">
          <div class="form-group">
            <label class="form-label">Name</label>
            <input class="input" type="text" bind:value={form.name} placeholder="e.g. Slack notifications" />
          </div>
          <div class="form-group">
            <label class="form-label">Payload URL</label>
            <input class="input" type="url" bind:value={form.url} placeholder="https://example.com/webhook" />
          </div>
          <div class="form-group">
            <label class="form-label">Events</label>
            <label class="event-option">
              <input type="checkbox" checked={isWildcard} onchange={toggleWildcard} />
              <span>All events (wildcard)</span>
            </label>
            {#if !isWildcard}
              <div class="event-groups">
                {#each allEvents as group}
                  <div class="event-group">
                    <div class="event-group-label">{group.group}</div>
                    {#each group.events as evt}
                      <label class="event-option">
                        <input type="checkbox" checked={form.events.includes(evt)} onchange={() => toggleEvent(evt)} />
                        <span>{evt}</span>
                      </label>
                    {/each}
                  </div>
                {/each}
              </div>
            {/if}
          </div>
          <div class="form-group">
            <label class="form-label">Custom Headers</label>
            <textarea class="input" bind:value={form.headers} rows="3" placeholder="X-Custom-Header: value&#10;Authorization: Bearer token"></textarea>
          </div>
          <div class="form-group" style="display:flex; align-items:center; gap:8px;">
            <label class="toggle-switch">
              <input type="checkbox" bind:checked={form.active} />
              <span class="toggle-slider"></span>
            </label>
            <span style="font-size:13px;">Active</span>
          </div>

          {#if editing}
            <div class="form-group">
              <label class="form-label">Secret</label>
              <div style="display:flex; align-items:center; gap:8px;">
                <code style="font-size:12px; color:var(--text-tertiary);">••••••••••••••••</code>
                {#if confirmRegenId === editing.id}
                  <button class="wh-btn" style="color:var(--danger);" onclick={() => handleRegenerate(editing.id)}>Confirm regenerate</button>
                  <button class="wh-btn" onclick={() => { confirmRegenId = null; }}>Cancel</button>
                {:else}
                  <button class="wh-btn" onclick={() => { confirmRegenId = editing.id; }}>Regenerate</button>
                {/if}
              </div>
            </div>
            <div class="form-group">
              <button class="wh-btn" style="color:var(--danger); font-size:12px;" onclick={() => { confirmDeleteId = editing.id; }}>Delete this webhook</button>
            </div>
          {/if}

          {#if confirmDeleteId}
            <div style="display: flex; align-items: center; gap: 8px; font-size: 13px; padding: 8px 0;">
              <span>Delete this webhook?</span>
              <button class="wh-btn" style="color:var(--danger);" onclick={() => { handleDelete(confirmDeleteId); showModal = false; }}>Yes, delete</button>
              <button class="wh-btn" onclick={() => { confirmDeleteId = null; }}>Cancel</button>
            </div>
          {/if}
        </div>

        <div class="modal-footer">
          <button class="btn btn-secondary" onclick={() => showModal = false}>Cancel</button>
          <button class="btn btn-primary" onclick={handleSave} disabled={saving}>
            {saving ? 'Saving...' : (editing ? 'Save' : 'Create webhook')}
          </button>
        </div>
      {/if}
    </div>
  </div>
{/if}

<style>
  .int-block {
    margin-top: var(--space-2xl);
    padding-top: var(--space-2xl);
    border-top: 1px solid var(--border-primary);
  }
  .int-block:first-of-type {
    margin-top: var(--space-xl);
    padding-top: 0;
    border-top: none;
  }
  .int-block-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    margin-bottom: var(--space-lg);
  }
  .int-block-title {
    font-size: 15px;
    font-weight: 600;
    color: var(--text-primary);
    margin: 0 0 var(--space-xs);
  }
  .int-block-desc {
    font-size: var(--font-size-sm);
    color: var(--text-secondary);
    margin: 0 0 var(--space-lg);
  }
  .int-block-desc code {
    font-family: var(--font-mono);
    background: var(--bg-tertiary);
    padding: 1px 4px;
    border-radius: 3px;
  }
  .form-hint {
    font-size: var(--font-size-xs);
    color: var(--text-tertiary);
    margin-top: var(--space-xs);
  }
  .form-hint code {
    font-family: var(--font-mono);
    background: var(--bg-tertiary);
    padding: 1px 4px;
    border-radius: 3px;
  }
  .key-banner {
    margin-bottom: var(--space-lg);
    padding: var(--space-md);
    background: var(--bg-tertiary);
    border-radius: var(--radius-sm);
  }
  .key-code {
    flex: 1;
    font-family: var(--font-mono);
    font-size: var(--font-size-sm);
    color: var(--text-primary);
    word-break: break-all;
  }
  .api-key-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: var(--space-sm) 0;
    border-bottom: 1px solid var(--border-faint);
  }

  /* Webhooks */
  .webhook-list { display: flex; flex-direction: column; }
  .webhook-row {
    display: flex; align-items: center; justify-content: space-between;
    padding: 16px 0; border-bottom: 1px solid var(--border-primary); gap: 16px;
  }
  .webhook-row.inactive { opacity: 0.5; }
  .webhook-info { flex: 1; min-width: 0; }
  .webhook-name { font-size: 14px; font-weight: 500; color: var(--text-primary); margin-bottom: 2px; }
  .webhook-url { font-size: 12px; font-family: var(--font-mono); color: var(--text-tertiary); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .webhook-meta { font-size: 11px; color: var(--text-tertiary); text-transform: uppercase; letter-spacing: 0.03em; margin-top: 4px; }
  .webhook-actions { display: flex; align-items: center; gap: 8px; flex-shrink: 0; }
  .wh-btn {
    background: none; border: none; color: var(--text-tertiary); font-size: 12px;
    cursor: pointer; padding: 4px 8px; border-radius: 4px;
  }
  .wh-btn:hover { color: var(--text-primary); background: var(--bg-hover); }

  .toggle-switch { position: relative; display: inline-block; width: 32px; height: 18px; }
  .toggle-switch input { opacity: 0; width: 0; height: 0; }
  .toggle-slider {
    position: absolute; cursor: pointer; inset: 0; background: var(--border-primary);
    border-radius: 18px; transition: 0.15s;
  }
  .toggle-slider::before {
    content: ''; position: absolute; height: 14px; width: 14px; left: 2px; bottom: 2px;
    background: white; border-radius: 50%; transition: 0.15s;
  }
  .toggle-switch input:checked + .toggle-slider { background: var(--accent); }
  .toggle-switch input:checked + .toggle-slider::before { transform: translateX(14px); }

  .deliveries-panel { padding: 0 0 16px; border-bottom: 1px solid var(--border-primary); }
  .deliveries-table { width: 100%; font-size: 12px; border-collapse: collapse; }
  .deliveries-table th {
    text-align: left; font-weight: 500; font-size: 11px; text-transform: uppercase;
    color: var(--text-tertiary); padding: 6px 8px; border-bottom: 1px solid var(--border-primary); letter-spacing: 0.03em;
  }
  .deliveries-table td { padding: 6px 8px; color: var(--text-secondary); border-bottom: 1px solid var(--border-faint); }
  .deliveries-table code { font-size: 11px; background: var(--bg-hover); padding: 1px 5px; border-radius: 3px; }
  .status-dot { display: inline-block; width: 7px; height: 7px; border-radius: 50%; margin-right: 4px; vertical-align: middle; }

  .secret-banner { background: var(--bg-tertiary); border-radius: 6px; padding: 16px; margin-bottom: 8px; }
  .secret-label { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; color: var(--text-tertiary); margin-bottom: 4px; }
  .secret-value { display: flex; align-items: center; gap: 8px; }
  .secret-value code { font-size: 12px; word-break: break-all; flex: 1; }

  .event-groups { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-top: 8px; }
  .event-group-label { font-size: 11px; font-weight: 600; text-transform: uppercase; color: var(--text-tertiary); letter-spacing: 0.04em; margin-bottom: 4px; }
  .event-option { display: flex; align-items: center; gap: 6px; font-size: 13px; color: var(--text-secondary); cursor: pointer; padding: 2px 0; }
  .event-option input[type="checkbox"] { accent-color: var(--accent); }

  @media (max-width: 768px) {
    .deliveries-table {
      display: block;
      overflow-x: auto;
      -webkit-overflow-scrolling: touch;
    }

    .event-groups {
      grid-template-columns: 1fr;
    }

    .webhook-row {
      flex-wrap: wrap;
    }

    .api-key-row {
      flex-wrap: wrap;
      gap: var(--space-sm);
    }

    .int-block-header {
      flex-wrap: wrap;
      gap: var(--space-sm);
    }
  }
</style>
